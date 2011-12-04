<?php

return array (
    'default' => array (
        'doc-dir'           => DOCROOT.'temp/wsdl/',
        // class prefix, which will be remove
        'class-prefix'      => 'controller_',
        'class-postfix'     => '',
        // classes exclude from expose
        'class-exclude'     => array(),
        'method-prefix'     => 'action_',
        'method-postfix'    => '',
        // methods exclude from expose
        'method-exclude'    => array('__construct', '__call', 'before', 'after'),
        'classes'           => array(
            // Class name
            'wsdl_document' => 'http://api.example.com/:class',
            // Module name
            'utilize'       => 'http://api.example.com/:class',
            // Directory
            'controller'.DIRECTORY_SEPARATOR   => 'http://api.example.com/:class',
        )
    )
);
