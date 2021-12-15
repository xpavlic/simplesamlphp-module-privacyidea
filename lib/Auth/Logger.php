<?php

class Logger implements PILog
{
    /**
     * This function allows to show the debug messages from privacyIDEA server
     * @param $message
     */
    public function piDebug($message)
    {
        SimpleSAML\Logger::debug($message);
    }

    /**
     * This function allows to show the debug messages from privacyIDEA server
     * @param $message
     */
    public function piError($message)
    {
        SimpleSAML\Logger::error($message);
    }
}