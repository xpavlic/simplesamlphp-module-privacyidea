<?php

namespace SimpleSAML\Module\privacyidea\Auth\Process;

use PIBadRequestException;
use PILog;
use PrivacyIDEA;
use SimpleSAML\Auth\ProcessingFilter;
use SimpleSAML\Auth\State;
use SimpleSAML\Error\BadRequest;
use SimpleSAML\Error\Exception;
use SimpleSAML\Logger;
use SimpleSAML\Module;
use SimpleSAML\Module\privacyidea\Auth\utils;
use SimpleSAML\Utils\HTTP;

/**
 * This authentication processing filter allows you to add a second step
 * authentication against privacyIDEA
 *
 * @author Cornelius Kölbel <cornelius.koelbel@netknights.it>
 * @author Jean-Pierre Höhmann <jean-pierre.hoehmann@netknights.it>
 * @author Lukas Matusiewicz <lukas.matusiewicz@netknights.it>
 */

require_once((dirname(__FILE__, 3)) . '/sdk-php/src/SDK-Autoloader.php');

class PrivacyideaAuthProc extends ProcessingFilter implements PILog
{
    /* @var array This contains the authproc configuration which is set in metadata */
    private $authProcConfig;
    /* @var PrivacyIDEA This is an object from privacyIDEA class */
    private $pi;

    /**
     * privacyIDEA constructor.
     * @param array $config Authproc configuration.
     * @param mixed $reserved
     */
    public function __construct(array $config, $reserved)
    {
        assert('array' === gettype($config));
        parent::__construct($config, $reserved);
        $this->authProcConfig = $config;

        // Create a new object from privacyIDEA class (SDK) and adjust the property which are needed by triggerChallenge()
        if (!empty($this->authProcConfig['privacyideaServerURL']))
        {
            $this->pi = new PrivacyIDEA("simpleSAMLphp", $this->authProcConfig['privacyideaServerURL']);
            if (!empty($this->authProcConfig['sslVerifyHost']))
            {
                $this->pi->sslVerifyHost = $this->authProcConfig['sslVerifyHost'];
            }
            if (!empty($this->authProcConfig['sslVerifyPeer']))
            {
                $this->pi->sslVerifyPeer = $this->authProcConfig['sslVerifyPeer'];
            }
            if (!empty($this->authProcConfig['serviceAccount']))
            {
                $this->pi->serviceAccountName = $this->authProcConfig['serviceAccount'];
            }
            if (!empty($this->authProcConfig['servicePass']))
            {
                $this->pi->serviceAccountPass = $this->authProcConfig['servicePass'];
            }
            if (!empty($this->authProcConfig['serviceRealm']))
            {
                $this->pi->serviceAccountRealm = $this->authProcConfig['serviceRealm'];
            }
            if (!empty($this->authProcConfig['privacyideaServerURL']))
            {
                $this->pi->logger = $this;
            }
        } else
        {
           Logger::error("privacyIDEA: privacyIDEA server url is not set in class: privacyidea:privacyidea in metadata.");
        }
    }

    /**
     * Run the filter.
     * @param array $state
     * @throws Exception if authentication fails
     */
    public function process(&$state)
    {
        Logger::info("privacyIDEA Auth Proc Filter: Entering process function.");
        assert('array' === gettype($state));

        // Update state before starting the authentication process
        $state['privacyidea:serverconfig'] = $this->authProcConfig;

        // If set in config, allow to check the IP of the client and to control the 2FA depending on the client IP.
        // It can be used to configure that a user does not need to provide a second factor when logging in from the local network.
        if (!empty($this->authProcConfig['excludeClientIPs']))
        {
            $state['privacyIDEA']['enabled'][0] = $this->matchIP(utils::getClientIP(), $this->authProcConfig['excludeClientIPs']);
        }

        // If set to "true" in config, selectively disable the privacyIDEA authentication using the entityID and/or SAML attributes.
        if (!empty($this->authProcConfig['checkEntityID']) && $this->authProcConfig['checkEntityID'] === 'true')
        {
            $stateID = State::saveState($state, 'privacyidea:privacyidea');
            $stateID = $this->checkEntityID($this->authProcConfig, $stateID);
            $state = State::loadState($stateID, 'privacyidea:privacyidea');
        }

        // Check if privacyIDEA is disabled by a filter
        if (utils::checkPIAbility($state, $this->authProcConfig) === true)
        {
            Logger::debug("privacyIDEA: privacyIDEA is disabled by a filter");
            return;
        }

        $username = $state["Attributes"][$this->authProcConfig['uidKey']][0];
        $stateID = State::saveState($state, 'privacyidea:privacyidea');

        // Check if it should be controlled that user has no tokens and a new token should be enrolled.
        if (!empty($this->authProcConfig['doEnrollToken']) && $this->authProcConfig['doEnrollToken'] === 'true')
        {
            $stateID = $this->enrollToken($stateID, $username);
        }

        // Check if all the challenges should be triggered at once and if possible, do it
        if (!empty($this->authProcConfig['doTriggerChallenge']) && $this->authProcConfig['doTriggerChallenge'] === 'true')
        {
            $stateID = State::saveState($state, 'privacyidea:privacyidea');
            if (!$this->pi->serviceAccountAvailable())
            {
                Logger::error('privacyIDEA: service account or password is not set in config. Cannot to do trigger challenge.');
            } else
            {
                //try {
                $response = $this->pi->triggerChallenge($username);
                $stateID = utils::processPIResponse($stateID, $response);
                //} catch (PIBadRequestException $e) {
                // show some text in ui "Authentication server unreachable."

                //}
            }
        } elseif (!empty($this->authProcConfig['tryFirstAuthentication']) && $this->authProcConfig['tryFirstAuthentication'] === 'true')
        {

            $response = utils::authenticatePI($state,
                                                                      array('pass' => $this->authProcConfig['tryFirstAuthPass']),
                                                                      $this->authProcConfig);

            if (empty($response->multiChallenge) && $response->value)
            {
                return;
            }
        }

        $state = State::loadState($stateID, 'privacyidea:privacyidea');

        // Procfilters work already done. Save the state and go to formbuilder.php to authenticate
        // Set authprocess as authentication method and save the state
        $state['privacyidea:privacyidea']['authenticationMethod'] = "authprocess";
        $state['privacyidea:privacyidea:ui']['step'] = 2;
        $stateID = State::saveState($state, 'privacyidea:privacyidea');

        // Go to otpform
        $url = Module::getModuleURL('privacyidea/formbuilder.php');
        HTTP::redirectTrustedURL($url, array('StateId' => $stateID));
    }

    /**
     * This function check if user has a token and if not - help to enroll a new one in UI.
     * @param string $stateID
     * @param string $username
     * @return string
     * @throws PIBadRequestException
     */
    private function enrollToken($stateID, $username)
    {
        assert('string' === gettype($username));
        assert('string' === gettype($stateID));

        $state = State::loadState($stateID, 'privacyidea:privacyidea');

        // Error if no serviceAccount or servicePass
        if ($this->pi->serviceAccountAvailable() === false)
        {
            Logger::error("privacyIDEA: service account for token enrollment is not set!");
        } else
        {
            // Compose params
            $genkey = 1;
            $type = $this->authProcConfig['tokenType'];
            $description = "Enrolled with simpleSAMLphp";

            // Call SDK's enrollToken()
            $response = $this->pi->enrollToken($username, $genkey, $type, $description);

            if (!empty($response->errorMessage))
            {
                Logger::error("PrivacyIDEA server: Error code: " . $response->errorCode . ", Error message: " . $response->errorMessage);
                $state['privacyidea:privacyidea']['errorCode'] = $response->errorCode;
                $state['privacyidea:privacyidea']['errorMessage'] = $response->errorMessage;
            }

            // Nullcheck
            if ($response === null)
            {
                throw new BadRequest(
                    "privacyIDEA: We were not able to read the response from the PI server.");
            }

            // If we have a response from PI - save QR Code into state to show it soon
            // and enroll a new token for the user
            if (!empty($response->detail->googleurl->img))
            {
                $state['privacyidea:tokenEnrollment']['tokenQR'] = $response->detail->googleurl->img;
            }
            return State::saveState($state, 'privacyidea:privacyidea');
        }
        return "";
    }

    /**
     * This is the help function to exclude some IP from 2FA. Only if is set in config.
     * @param $clientIP
     * @param $excludeClientIPs
     * @return bool|void
     */
    private function matchIP($clientIP, $excludeClientIPs)
    {
        assert('string' === gettype($clientIP));
        $clientIP = ip2long($clientIP);

        $match = false;
        foreach ($excludeClientIPs as $ipAddress)
        {
            if (strpos($ipAddress, '-'))
            {
                $range = explode('-', $ipAddress);
                $startIP = ip2long($range[0]);
                $endIP = ip2long($range[1]);
                $match = $clientIP >= $startIP && $clientIP <= $endIP;
            } else
            {
                $match = $clientIP === ip2long($ipAddress);
            }
            if ($match)
            {
                break;
            }
        }
        return $match;
    }

    /**
     * This function allows the selective deactivation of privacyIDEA for a list of regular expressions
     * which match SAML service provider entityIDs.
     * The filter checks the entityID in the SAML request against a list of regular expressions and sets the state variable
     * $state[enabledPath][enabledKey][0] to false on match, which can be used to disable privacyIDEA.
     * For any value in excludeEntityIDs, the config parameter includeAttributes may be used to enable privacyIDEA for a subset
     * of users which have these attribute values (e.g. memberOf).
     * @param array $authProcConfig
     * @param string $stateID
     * @return string
     */
    private function checkEntityID($authProcConfig, $stateID)
    {
        Logger::debug("Checking requesting entity ID for privacyIDEA");
        $state = State::loadState($stateID, 'privacyidea:privacyidea');

        $excludeEntityIDs = $authProcConfig['excludeEntityIDs'] ?: array();
        $includeAttributes = $authProcConfig['includeAttributes'] ?: array();
        $setPath = $authProcConfig['setPath'] ?: "";
        $setKey = $authProcConfig['setKey'] ?: '';

        // the default return value is true, privacyIDEA should be enabled by default.
        $ret = true;
        $requestEntityID = $state["Destination"]["entityid"];

        // if the requesting entityID matches the given list set the return parameter to false
        Logger::debug("privacyidea:checkEntityID: Requesting entityID is " . $requestEntityID);
        $matchedEntityIDs = $this->strMatchesRegArr($requestEntityID, $excludeEntityIDs);
        if ($matchedEntityIDs)
        {
            $ret = false;
            $entityIDKey = $matchedEntityIDs[0];
            Logger::debug("privacyidea:checkEntityID: Matched entityID is " . $entityIDKey);

            // if there is also a match for any attribute value in the includeAttributes
            // fall back to the default return value: true
            if (isset($includeAttributes[$entityIDKey]))
            {
                foreach ($includeAttributes[$entityIDKey] as $attrKey => $attrRegExpArr)
                {
                    if (isset($state["Attributes"][$attrKey]))
                    {
                        foreach ($state["Attributes"][$attrKey] as $attrVal)
                        {
                            $matchedAttrs = $this->strMatchesRegArr($attrVal, $attrRegExpArr);

                            if (!empty($matchedAttrs))
                            {
                                $ret = true;
                                Logger::debug("privacyidea:checkEntityID: Requesting entityID in " .
                                                         "list, but excluded by at least one attribute regexp \"" . $attrKey .
                                                         "\" = \"" . $matchedAttrs[0] . "\".");
                                break;
                            }
                        }
                    } else
                    {
                        Logger::debug("privacyidea:checkEntityID: attribute key " .
                                                 $attrKey . " not contained in request");
                    }
                }
            }
        } else
        {
            Logger::debug("privacyidea:checkEntityID: Requesting entityID " .
                                     $requestEntityID . " not matched by any regexp.");
        }

        $state[$setPath][$setKey][0] = $ret;

        $stateID = State::saveState($state, 'privacyidea:privacyidea');

        if ($ret)
        {
            $retStr = "true";
        } else
        {
            $retStr = "false";
        }
        Logger::debug("Setting \$state[" . $setPath . "][" . $setKey . "][0] = " . $retStr . ".");

        return $stateID;
    }

    /**
     * This is the help function for checkEntityID() and checks a given string against an array with regular expressions.
     * It will return an array with matches.
     * @param string $str
     * @param array $reg_arr
     * @return array
     */
    private function strMatchesRegArr($str, array $reg_arr)
    {
        $retArr = array();

        foreach ($reg_arr as $reg)
        {
            if ($reg[0] != "/")
            {
                $reg = "/" . $reg . "/";
            }
            Logger::debug("privacyidea:checkEntityID: test regexp " . $reg . " against the string " . $str);

            if (preg_match($reg, $str))
            {
                $retArr[] = $reg;
            }
        }
        return $retArr;
    }

    /**
     * This function allows to show the debug messages from privacyIDEA server
     * @param $message
     */
    public function piDebug($message)
    {
        Logger::debug($message);
    }

    /**
     * This function allows to show the debug messages from privacyIDEA server
     * @param $message
     */
    public function piError($message)
    {
        Logger::error($message);
    }
}