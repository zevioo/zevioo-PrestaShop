<?php
/**
 * 2017 zevioo.com
*
*  @author    zevioo.com <support@zevioo.com>
*  @copyright 2017 zevioo.com
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

if (!defined('_PS_VERSION_'))
	exit;

class Zevioo extends Module
{

	protected $configOptions = array(
		'ZEVIOO_USERNAME',
		'ZEVIOO_PASSWORD'
	);

	public function __construct()
	{
		$this->name = 'zevioo';
		$this->tab = 'others';
		$this->version = '1.0.0';
		$this->author = 'Vnphpexpert';
		$this->service_new_order_url = 'https://api.zevioo.com/main.svc/custpurchase';
		$this->service_cancel_order_url = 'https://api.zevioo.com/main.svc/cnlpurchase';

		$this->displayName = $this->l('Zevioo.com');
		$this->description = $this->l('Send Order Details to Zevioo via API.');
		$this->confirmUninstall = $this->l('Do you want to uninstall this module?');

		parent::__construct();
    }

	public function install()
	{
		if (!function_exists('curl_init')){
			$this->setError($this->l('Zevioo.com requires cURL.'));
        }

		if (!parent::install() || !$this->registerHook('actionValidateOrder') || !$this->registerHook('actionOrderStatusPostUpdate')){
			return false;
        } else {
			return true;
        }
	}

	public function uninstall()
	{
		foreach($this->configOptions as $configOption){
			Configuration::deleteByName($configOption);
		}

		return parent::uninstall();
	}

	public function getContent()
	{
		$output = null;

		if (Tools::isSubmit('submit'.$this->name)){
			foreach($this->configOptions as $updateOption){
				$val = (string)Tools::getValue($updateOption);
				if(!empty($val)){
					Configuration::updateValue($updateOption, $val);
				}
			}

			$output = $this->displayConfirmation($this->l('Settings Updated'));
		}

		return $output.$this->displayForm();
	}

	public function displayForm()
	{
		$fields_form = array();

		$fields_form[0]['form'] = array(
				'legend' => array('title' => $this->l('Settings')),
				'input' => array(
						array(
								'type' => 'text',
								'label' => $this->l('Your Zevioo.com Username'),
								'name' => 'ZEVIOO_USERNAME',
								'size' => 20,
								'required' => true
						),
						array(
								'type' => 'text',
								'label' => $this->l('Your Zevioo.com Password'),
								'name' => 'ZEVIOO_PASSWORD',
								'size' => 20,
								'required' => true
						),

				),
				'submit' => array(
						'title' => $this->l('Save'),
						'class' => 'button'
				)
		);

		$helper = new HelperForm();

		$helper->module = $this;
		$helper->name_controller = $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		$helper->title = $this->displayName;
		$helper->show_toolbar = true;
		$helper->toolbar_scroll = true;
		$helper->submit_action = 'submit'.$this->name;
		$helper->toolbar_btn = array(
				'save' =>
				array(
						'desc' => $this->l('Save'),
						'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
						'&token='.Tools::getAdminTokenLite('AdminModules'),
				),
				'back' => array(
						'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
						'desc' => $this->l('Back to list')
				)
		);

		foreach($this->configOptions as $configOption){
			$helper->fields_value[$configOption] = Configuration::get($configOption);
		}

		return $helper->generateForm($fields_form);
	}

    protected function prepareOrderData($params){
        $order = $params['order'];
        $customer = $params['customer'];
        $first_name = $customer->firstname;
        $last_name = $customer->lastname;
        $email = $customer->email;

        $products = $order->getProducts();
        $products_array = array();

        foreach ($products as $product)
        {
			$product_id = $product['product_id'];

			$image = Image::getCover($product_id);
			$link = new Link;
			$prod = new Product($product_id, false, Context::getContext()->language->id);
			$imagePath = $link->getImageLink($prod->link_rewrite, $image['id_image'], 'home_default');
			$EAN = '';
			if($product['product_ean13'] != '0' && $product['product_ean13'] != ''){
				$EAN = $product['product_ean13'];
			} else if($product['product_upc'] != ''){
				$EAN = $product['product_upc'];
			} else if($product['product_reference'] != ''){
				$EAN = $product['product_reference'];
			} else {
				$EAN = $product['product_id'];
			}
            $product_item = array(
				'CD' => $product['product_id'],
				'EAN' => $EAN,
                'NM' => $product['product_name'],
				'IMG' => $_SERVER['REQUEST_SCHEME'].'://'.$imagePath,
                'PRC' => $product['product_price'],
				'QTY' => $product['product_quantity']
            );

            $products_array[] = $product_item;
        }

        $orderData = array(
			'USR' => Configuration::get('ZEVIOO_USERNAME'),
			'PSW' => Configuration::get('ZEVIOO_PASSWORD'),
			'OID' => $params['order']->id,
			'PDT' => date('Y-m-d H:i:s'),
			'DDT' => '',
			'EML' => $email,
            'FN' => $first_name,
			'LN' => substr($last_name,0,1),
			'ITEMS' => $products_array
			
			
        );

        return $orderData;
    }

	public function hookActionValidateOrder($params)
	{
		$id_order = intval($params['order']->id);
		$orderData = $this->prepareOrderData($params);
		$returnData = $this->apiPostRequest($this->service_new_order_url, $orderData);
		
		if($returnData['http_status'] == '200'){
			$msg = new Message();
			$msg->message = "Success Send New Order: ". print_r($returnData['data'], true);
			$msg->id_order = $id_order;
			$msg->private = 1;
			$msg->add();
		} else {
			$msg = new Message();
			$msg->message = "Error Send New Order: ". print_r($returnData['data'], true);
			$msg->id_order = $id_order;
			$msg->private = 1;
			$msg->add();
		}
	}
	
	public function hookActionOrderStatusPostUpdate($params)
	{
		//cancel order
		if ($params['newOrderStatus']->id == (int)Configuration::get('PS_OS_CANCELED')){
			$id_order = intval($params['id_order']);
			$orderData = array(
								'USR' => Configuration::get('ZEVIOO_USERNAME'),
								'PSW' => Configuration::get('ZEVIOO_PASSWORD'),
								'OID' => $id_order,
								'CDT' => date('Y-m-d H:i:s')
								);
			$returnData = $this->apiPostRequest($this->service_cancel_order_url, $orderData);
			
			if($returnData['http_status'] == '200'){
				$msg = new Message();
				$msg->message = "Success Cancel Order: ". print_r($returnData['data'], true);
				$msg->id_order = $id_order;
				$msg->private = 1;
				$msg->add();
			} else {
				$msg = new Message();
				$msg->message = "Error Cancel Order: ". print_r($returnData['data'], true);
				$msg->id_order = $id_order;
				$msg->private = 1;
				$msg->add();
			}
		}
	}
	
	protected function apiPostRequest($url, $postData){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $data = curl_exec($ch);
		$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
		$data = json_decode($data);
		return array('data'=>$data, 'http_status'=>$http_status);
    }
}
