<?php
/**
 * Easy creation of WSDL documents
 *
 * @package    Wsdl
 * @category   Controller
 * @author     Yahasana <42424861@qq.com>
 * @copyright  (c) 2011 Yahasana
 */
class Controller_Wsdl extends Controller {

    public function action_index()
    {
        $config = Kohana::config('todolist.acl');

        // create new parser
        $wsdl = new Wsdl_Document();

        // set service name
        $wsdl->name('MyService');

        // add an array of classes
        // $wsdl->add_class(array(
        //     'Controller_utilize' => 'http://example.com/server',
        // ));

        //$wsdl->save('document.wsdl'); // Result: bool

        $this->request->response = $wsdl->get_document();        // Result: string - WSDL document

        //$wsdl->validate();            // Result: bool
    }

}
