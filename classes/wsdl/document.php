<?php defined('SYSPATH') or die('No direct script access.');
/**
 * Easy creation of WSDL documents
 *
 * @package    Wsdl
 * @category   Core
 * @author     Michal Kocian <michal@kocian.name>
 * @author     Yahasana <42424861@qq.com>
 * @copyright  (c) 2010 Michal Kocian
 * @copyright  (c) 2011 Yahasana
 */
class Wsdl_Document {

    protected $config;

    /**
     * @var SimpleXMLElement Výsledný WDSL dokument
     */
    protected $wsdl = NULL;

    /**
     * @var string The resulting WSDL document
     */
    protected $name = NULL;

    /**
     * @var array Classes to parse
     */
    protected $classes = array();

    /**
     * @var array Methods informations
     */
    protected $items = array();

    /**
     * @var bool Modified input
     */
    protected $modified = TRUE;

    public function __construct($type = NULL)
    {
        if($type === NULL) $type = 'default';

        $this->config = Kohana::config("wsdl")->get($type);

        if( ! empty($this->config['classes']))
        {
            $classes    = array();
            $modules    = Kohana::modules();
            $files      = Arr::flatten(Kohana::list_files('classes'));
            $prefix     = str_replace('_', DIRECTORY_SEPARATOR, trim($this->config['class-prefix'],'_'));

            foreach($this->config['classes'] as $class => $uri)
            {
                if(class_exists($class))
                {
                    $classes[$class] = $uri;
                }
                elseif(isset($modules[$class]))
                {
                    if(empty($prefix))
                    {
                        $_files = Arr::flatten(Kohana::list_files(NULL, array($modules[$class].'classes'.DIRECTORY_SEPARATOR)));
                    }
                    else
                    {
                        $_files = Arr::flatten(Kohana::list_files($prefix, array($modules[$class].'classes'.DIRECTORY_SEPARATOR)));
                    }
                    foreach($_files as $file => $path)
                    {
                        if(substr($file, -4) !== '.php') continue;

                        $classes[str_replace(array(DIRECTORY_SEPARATOR, '.php'), array('_', ''), $file)] = $uri;
                    }
                }
                else
                {
                    foreach($files as $file => $path)
                    {
                        if(stripos($file, $class) !== 8 OR substr($file, -4) !== '.php') continue;

                        $classes[str_replace(array(DIRECTORY_SEPARATOR, '.php'), array('_', ''), substr($file, 8))] = $uri;
                    }
                }
            }

            $this->add_class($classes, TRUE);
        }
    }

    /**
     * Add class for processing
     *
     * @param array $class Class name
     */
    public function add_class(array $classes, $ignore = FALSE)
    {
        foreach ($classes as $class => $uri)
        {
            // Check class
            if ( ! class_exists($class))
            {
                if($ignore === FALSE)
                {
                    // Class doesn't exist
                    throw new Wsdl_Exception('Class "'.$class.'" doesn\'t exist!');
                }
                else
                {
                    continue;
                }
            }

            if( ! in_array($class, $this->config['class-exclude']))
                $this->classes[strtolower($class)] = $uri;
        }

        $this->modified = TRUE;
    }

    /**
     * Parse a PHPDoc comment for all parameters
     *
     * @param ReflectionMethod $method Method object
     * @return array Parsed comments
     */
    protected function parse_info($method)
    {
        // Tag content
        $tags = array();

        foreach ($method->getParameters() as $parameter)
        {
            // insert default type
            $tags['param'][$parameter->name] = array(
                'type' => 'xsd:anyType'
            );
        }

        $comment = $method->getDocComment();

        // Normalize all new lines to \n
        $comment = str_replace(array("\r\n", "\n"), "\n", $comment);

        // Remove the PHPDoc open/close tags and split
        $comment = array_slice(explode("\n", $comment), 1, -1);

        foreach ($comment as $i => $line)
        {
            // Remove all leading whitespace
            $line = preg_replace('/^\s*\* ?/m', '', $line);

            // Search this line for a tag
            if (preg_match('/^@(\S+)(?:\s*(.*))?$/', $line, $matches))
            {
                // This is a tag line
                unset($comment[$i]);

                $name = $matches[1];
                $text = isset($matches[2]) ? $matches[2] : '';

                switch ($name)
                {
                    case 'param':
                        preg_match('/^(\S+)\s+\$(\S+)(?:\s+(.*))?$/', $text, $matches);

                        // Add the tag
                        switch ($matches[1])
                        {
                            case 'int':
                            case 'float':
                            case 'string':
                                $type = 'xsd:'.$matches[1];
                                break;
                            case 'integer':
                                $type = 'xsd:int';
                                break;
                            case 'mixed':
                                $type = 'xsd:anyType';
                                break;
                            case 'array':
                                $type = 'soapenc:Array';
                                break;

                            default:
                                $type = 'xsd:'.$matches[1];
                                break;
                        }

                        // Add a new tag
                        $tags[$name][$matches[2]] = array(
                            'type' => $type,
                            'doc' => isset($matches[3]) ? $matches[3] : NULL,
                        );

                        break;
                    case 'return':
                        preg_match('/^(\S+)(?:\s(.*))?$/', $text, $matches);

                        // Add the tag
                        $tags[$name] = array(
                            'type' => $matches[1],
                            'doc' => isset($matches[2]) ? $matches[2] : NULL,
                        );

                        break;
                }
            }
            else
            {
                // Overwrite the comment line
                $comment[$i] = (string) $line;
            }
        }

        // Concat the comment lines back to a block of text
        $tags['doc'] = trim(implode("\n", $comment));

        if (isset($tags['doc']) AND $tags['doc'] === '')
        {
            unset($tags['doc']);
        }

        return $tags;
    }


    /**
     * Find all methods and parse their comments
     *
     * @return array
     */
    protected function parse_comments()
    {
        // Process all classes
        foreach ($this->classes as $class => $uri)
        {
            $class = new ReflectionClass($class);
            $cname = $class->getName();

            if( ! empty($this->config['class-prefix']))
            {
                if(stripos($cname, $this->config['class-prefix']) !== 0) continue;
                $cname = substr($cname, strlen($this->config['class-prefix']));
            }

            if( ! empty($this->config['class-postfix']))
            {
                if(strripos($cname, $this->config['class-postfix']) !== 0) continue;
                $cname = substr($cname, 0, -strlen($this->config['class-postfix']));
            }

            $cname = strtolower($cname);

            // Find all methods
            foreach ($class->getMethods() as $method)
            {
                $mname = $method->getName();

                // Include only public class
                if ($method->isPublic() AND ! in_array($mname, $this->config['method-exclude']))
                {
                    if( ! empty($this->config['method-prefix']))
                    {
                        if(stripos($mname, $this->config['method-prefix']) !== 0) continue;
                        $mname = substr($mname, strlen($this->config['method-prefix']));
                    }

                    if( ! empty($this->config['method-postfix']))
                    {
                        if(strripos($mname, $this->config['method-postfix']) !== 0) continue;
                        $mname = substr($mname,  0, -strlen($this->config['method-postfix']));
                    }

                    // Parse PHPDoc
                    $this->items[$cname][$mname] = $this->parse_info($method);
                }
            }
        }

        return $this;
    }

    /**
     * Set WSDL document name
     *
     * @param string $name
     */
    public function name($name = NULL)
    {
        if($name === NULL)
            return $this->name;

        $this->name = $name;

        $this->modified = TRUE;

        return $this;
    }

    /**
     * Create new WSDL document
     *
     * @return bool
     */
    protected function create()
    {
        // Create new element
        $this->wsdl = new SimpleXMLElement('<definitions name="'.$this->name.'" targetNamespace="urn:'.$this->name.'" xmlns:typens="urn:'.$this->name.'" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/" xmlns:wsdl="http://schemas.xmlsoap.org/wsdl/" xmlns="http://schemas.xmlsoap.org/wsdl/" />', NULL, FALSE, 'wsdl');

        // Find all methods and parse comments
        $this->parse_comments()

            // Create all messages
            ->create_message()

            // Create port types
            ->create_port_types()

            // Create binding
            ->create_binding()

            // Create service
            ->create_service();

        $this->modified = FALSE;

        return $this;
    }

    /**
     * Save WSDL document
     *
     * @param bool $filename
     * @return bool
     */
    public function save($filename)
    {
        if ($this->modified)
        {
            // create document
            $this->create();
        }

        if(is_dir($this->config['doc-dir']))
        {
            if ( ! mkdir($this->config['doc-dir'], 0700, true))
                throw new Wsdl_Exception('Can not make dir `'.$this->config['doc-dir'].'`');
        }

        if ( ! is_writable($this->config['doc-dir'].$filename))
        {
            // file is not writable
            throw new Wsdl_Exception('Can not write file `'.$filename.'`');
        }

        // save WSDL document
        return (bool) file_put_contents($this->config['doc-dir'].$filename, $this->wsdl->asXML());
    }

    /**
     * Get WSDL document
     *
     * @return string
     */
    public function get_document()
    {
        if ($this->modified)
        {
            // create document
            $this->create();
        }

        return $this->wsdl->asXML();
    }

    /**
     * Validate this WSDL document
     *
     * @return bool
     */
    public function validate()
    {
        if ($this->modified)
        {
            // create document
            $this->create();
        }

        $doc = new DomDocument;

        // find xml schema
        $xmlschema = Kohana::find_file('views', 'schema', 'xsd');

        // Load the xml document
        $doc->loadXML($this->wsdl->asXML());

        // Validate the XML file against the schema
        return $doc->schemaValidate($xmlschema);
    }

    /**
     * Create types
     */
    protected function create_types()
    {
        $this->wsdl->addChild('types');

        return $this;
    }

    /**
     * Create messages
     */
    protected function create_message()
    {
        foreach ($this->items as $class => $methods)
        {
            foreach ($methods as $method => $params)
            {
                // Creata input message
                $message = $this->wsdl->addChild('message');
                $message->addAttribute('name', $method);

                // Create params
                if (isset($params['param']))
                {
                    foreach ($params['param'] as $param_name => $param)
                    {
                        $part = $message->addChild('part');
                        $part->addAttribute('name', $param_name);
                        $part->addAttribute('type', $this->items[$class][$method]['param'][$param_name]['type']);
                    }
                }

                // Create response message only if PHPDoc comment @return exists
                if (isset($this->items[$class][$method]['return']))
                {
                    // Create response message
                    $message_response = $this->wsdl->addChild('message');
                    $message_response->addAttribute('name', $method.'Response');
                    $part_response = $message_response->addChild('part');
                    $part_response->addAttribute('name', $method.'Return');
                    $part_response->addAttribute('type', 'xsd:'.$this->items[$class][$method]['return']['type']);
                }
            }
        }

        return $this;
    }

    /**
     * Create port types
     */
    protected function create_port_types()
    {
        // Create portType for every class
        foreach ($this->items as $class => $methods)
        {
            $portType = $this->wsdl->addChild('portType');
            $portType->addAttribute('name', $class.'PortType');

            // Create operations
            foreach ($methods as $method => $params)
            {
                $operation = $portType->addChild('operation');
                $operation->addAttribute('name', $method);

                // Add a documentation
                if (isset($params['doc']))
                {
                    $documentation = $operation->addChild('documentation', $params['doc']);
                }

                $input = $operation->addChild('input');
                $input->addAttribute('message', 'typens:'.$method);

                // Add output only if PHPDoc comment @return exists
                if (isset($this->items[$class][$method]['return']))
                {
                    // Create output
                    $output = $operation->addChild('output');
                    $output->addAttribute('message', 'typens:'.$method.'Response');
                }
            }
        }

        return $this;
    }

    /**
     * Create binding
     */
    protected function create_binding()
    {
        // Create binding for all classes
        foreach ($this->items as $class => $methods)
        {
            // Create binding for a class
            $binding = $this->wsdl->addChild('binding');
            $binding->addAttribute('name', $class.'Binding');
            $binding->addAttribute('type', 'typens:'.$class.'PortType');

            $soap_binding = $binding->addChild('soap:binding', NULL, 'http://schemas.xmlsoap.org/wsdl/soap/');
            $soap_binding->addAttribute('style', 'rpc');
            $soap_binding->addAttribute('transport', 'http://schemas.xmlsoap.org/soap/http');

            // Create operations
            foreach ($methods as $method => $params)
            {
                $operation = $binding->addChild('operation');
                $operation->addAttribute('name', $method);

                $soap_operation = $operation->addChild('soap:operation', NULL, 'http://schemas.xmlsoap.org/wsdl/soap/');
                $soap_operation->addAttribute('soapAction', 'urn:'.$method.'Action');

                // Create input
                $input = $operation->addChild('input');
                $body = $input->addChild('soap:body', NULL, 'http://schemas.xmlsoap.org/wsdl/soap/');
                $body->addAttribute('namespace', 'urn:'.$this->name);
                $body->addAttribute('use', 'encoded');
                $body->addAttribute('encodingStyle', 'http://schemas.xmlsoap.org/soap/encoding/');

                // Add output only if PHPDoc comment @return exists
                if (isset($this->items[$class][$method]['return']))
                {
                    // Create output
                    $output = $operation->addChild('output');
                    $body = $output->addChild('soap:body', NULL, 'http://schemas.xmlsoap.org/wsdl/soap/');
                    $body->addAttribute('namespace', 'urn:'.$this->name);
                    $body->addAttribute('use', 'encoded');
                    $body->addAttribute('encodingStyle', 'http://schemas.xmlsoap.org/soap/encoding/');
                }
            }
        }

        return $this;
    }

    /**
     * Create service
     */
    protected function create_service()
    {
        $service = $this->wsdl->addChild('service');
        $service->addAttribute('name', $this->name.'Service');

        // Create ports for all classes
        foreach ($this->items as $class => $methods)
        {
            $port = $service->addChild('port');
            $port->addAttribute('name', $class.'Port');
            $port->addAttribute('binding', 'typens:'.$class.'Binding');

            // Uri for this service
            $address = $port->addChild('soap:address', NULL, 'http://schemas.xmlsoap.org/wsdl/soap/');
            $uri = $this->classes[strtolower($this->config['class-prefix'].$class.$this->config['class-postfix'])];
            $address->addAttribute('location', strtr($uri, array(':class' => str_replace('_', '/', $class))));
        }

        return $this;
    }
}