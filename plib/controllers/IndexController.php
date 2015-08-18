<?php

class IndexController extends pm_Controller_Action {

	public function init() {
		parent::init();

		// Init title for all actions
		$this->view->pageTitle = 'nimbusec Agent';

		// Init tabs for all actions
		$this->view->tabs = array(
			array(
				'title' => 'API',
				'action' => 'api',
			),
			array(
				'title' => 'Domains',
				'action' => 'domains',
			),
			array(
				'title' => 'Run settings',
				'action' => 'exec',
			),
		);
	}

	public function indexAction() {
		$this->_forward('api');
	}

	public function apiAction() {
		//read config file
		$string = file_get_contents(pm_Context::getVarDir() . '/agent.conf');
		$config = json_decode($string, true);
		$apikey = $config['key'];

		//if api key and secret are not present int the key-value-store read them from the config file
		if (!empty(pm_Settings::get('apikey'))) {
			$apikey = pm_Settings::get('apikey');
		}

		$apisecret = $config['secret'];
		if (!empty(pm_Settings::get('apisecret'))) {
			$apisecret = pm_Settings::get('apisecret');
		}


		$form = new pm_Form_Simple();
		$form->addElement('text', 'apikey', array(
			'label' => 'API Key',
			'value' => $apikey,
			'required' => true,
			'validators' => array(
				array('NotEmpty', true),
			),
		));
		$form->addElement('text', 'apisecret', array(
			'label' => 'API Secret',
			'value' => $apisecret,
			'required' => true,
			'validators' => array(
				array('NotEmpty', true),
			),
		));
		$form->addElement('text', 'apiserver', array(
			'label' => 'API Server',
			'value' => $config['apiserver'],
			'required' => true,
			'validators' => array(
				array('NotEmpty', true),
			),
		));

		$form->addControlButtons(array(
			'cancelLink' => pm_Context::getModulesListUrl(),
		));

		$err = false;
		if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
			//store settings from form into key-value-store
			pm_Settings::set('apikey', $form->getValue('apikey'));
			pm_Settings::set('apisecret', $form->getValue('apisecret'));
			pm_Settings::set('apiserver', rtrim($form->getValue('apiserver'),"/"));

			//if agent key and secret present in kv-store use them
			if (!empty(pm_Settings::get('agentkey')) && !empty(pm_Settings::get('agentkey'))) {
				$config['key'] = pm_Settings::get('agentkey');
				$config['secret'] = pm_Settings::get('agentsecret');
			} else {
				//if agent key and secret not present query from api
				require_once pm_Context::getPlibDir() . '/library/lib/Nimbusec.php';
				$nimbusec = new Modules_NimbusecAgentIntegration_Lib_Nimbusec();

				try {
					//get agent binary from api
					if (!$nimbusec->fetchAgent(pm_Context::getVarDir())) {
						//error!
						$err = true;
						$this->_status->addError(pm_Locale::lmsg('downloadError'));
					} else {
						//download and extract worked
						$host = $this->getHost();

						//get new token
						$token = $nimbusec->getAgentCredentials($host.'-plesk');

						pm_Settings::set('agentkey', $token['key']);
						pm_Settings::set('agentsecret', $token['secret']);
						pm_Settings::set('agenttoken-id', $token['id']);

						$config['key'] = pm_Settings::get('agentkey');
						$config['secret'] = pm_Settings::get('agentsecret');
					}
				} catch (NimbusecException $e) {
					$err = true;
					$this->_status->addError($e->getMessage());
				} catch (CUrlException $e) {
					$err = true;
					if (strpos($e->getMessage(), '401') || strpos($e->getMessage(), '403')) {
						$this->_status->addError(pm_Locale::lmsg('invalidAPICredentials'));
					}
					if (strpos($e->getMessage(), '404')) {
						$this->_status->addError(pm_Locale::lmsg('invalidAgentVersion'));
					}
				}
			}

			if (!$err) {
				$config['apiserver'] = rtrim($form->getValue('apiserver'),"/");

				file_put_contents(pm_Context::getVarDir() . '/agent.conf', json_encode($config, JSON_UNESCAPED_SLASHES));

				$this->_status->addMessage('info', pm_Locale::lmsg('savedMessage'));
			}
			$this->_helper->json(array('redirect' => pm_Context::getBaseUrl()));
		}

		$this->view->form = $form;
	}

	//get hostname from plesk api
	private function getHost() {
		$request = <<<DATA
<server>
	<get>
		<gen_info/>
	</get>
</server>
DATA;

		$resp = pm_ApiRpc::getService()->call($request);


		return $resp->server->get->result->gen_info->server_name;
	}

	//get all domains on the host from plesk api
	private function getDomainData() {
		$api = pm_ApiRpc::getService();
		$request = <<<DATA
<webspace>
	<get>
		<filter/>
		<dataset>
			<hosting-basic/>
		</dataset>
	</get>
</webspace>
DATA;

		$resp = $api->call($request);

		$data = array();
		foreach ($resp->webspace->get->result as $host) {
			$dom = rtrim((string) $host->data->gen_info->name,"/");
			foreach ($host->data->hosting->vrt_hst->property as $prop) {
				if ($prop->name == 'www_root') {
					$dir = (string) $prop->value;
					array_push($data, [$dom => $dir]);
				}
			}
		}
		$string = file_get_contents(pm_Context::getVarDir() . '/agent.conf');
		$config = json_decode($string, true);



		if (isset($_POST['submitted'])) {

			//todo: check domain
			//if returns true add to config
			$domainsAdd = array();
			$domains = $this->buildDomainArray($_POST['active']);
			require_once pm_Context::getPlibDir() . '/library/lib/Nimbusec.php';
			$nimbusec = new Modules_NimbusecAgentIntegration_Lib_Nimbusec();
			$err = false;
			foreach ($domains as $do => $di) {
				try {
					if ($nimbusec->checkDomain($do, $_POST['bundle'])) {
						$domainsAdd[$do] = $di;
					}
				} catch (NimbusecException $e) {
					if (!strpos($e->getMessage(), '409')) {
						$err = true;
						$this->_status->addError($e->getMessage());
					}
				} catch (CUrlException $e) {
					
					if (strpos($e->getMessage(), '401') || strpos($e->getMessage(), '403')) {
						$err = true;
						$this->_status->addError(pm_Locale::lmsg('invalidAPICredentials'));
						
					}
					if (strpos($e->getMessage(), '404')) {
						$err = true;
						$this->_status->addError(pm_Locale::lmsg('invalidAgentVersion'));
					}
					if (strpos($e->getMessage(), '409')) {
						//$err = true;
						$this->_status->addError('Domain '.$do.' is known on your account but seems to be disabled. Please add it to a bundle in order to allow enabling it for the agent');
					}
				} catch (Exception $e) {
					$this->_status->addError($e->getMessage());
				}
				
			}
			
			if (!$err) {
				$config['domains'] = $domainsAdd;

				file_put_contents(pm_Context::getVarDir() . '/agent.conf', json_encode($config, JSON_UNESCAPED_SLASHES));
				//$this->_status->addMessage('info', pm_Locale::lmsg('savedMessage'));
				$this->view->submitted=true;
				$this->view->infomsg=pm_Locale::lmsg('savedMessage');
			}
			
			//$this->_helper->json(array('redirect' => pm_Context::getActionUrl('index', 'domains')));
		}
		$d = $this->getListData($data, $config);


		$list = new pm_View_List_Simple($this->view, $this->_request);
		$list->setData($d);
		$list->setColumns(array(
			'column1' => array(
				'title' => '<input type="checkbox" name="act" onclick="updateState()" id="act"> Add/Remove Domain',
				'noEscape' => true,
				'sortable' => false,
			),
			'column2' => array(
				'title' => 'Domain',
				'noEscape' => true,
				'searchable' => true,
			),
			'column3' => array(
				'title' => pm_Locale::lmsg('directory'),
				'noEscape' => true,
			),
		));

		$list->setDataUrl(array('action' => 'list-data'));

		return $list;
	}

	public function domainsAction() {


		$list = $this->getDomainData();

		$this->view->list = $list;

		require_once pm_Context::getPlibDir() . '/library/lib/Nimbusec.php';

		$nimbusec = new Modules_NimbusecAgentIntegration_Lib_Nimbusec();
		try {
			$this->view->bundles = $nimbusec->getBundles();
			
			
		} catch (NimbusecException $e) {
			$this->_status->addError($e->getMessage());
		} catch (CUrlException $e) {
			$err = true;
			if (strpos($e->getMessage(), '401') || strpos($e->getMessage(), '403')) {
				$this->_status->addError(pm_Locale::lmsg('invalidAPICredentials'));
			}
			if (strpos($e->getMessage(), '404')) {
				$this->_status->addError(pm_Locale::lmsg('invalidAgentVersion'));
			}
		}
		
	}

	public function listDataAction() {
		$list = $this->getDomainData();

		$this->_helper->json($list->fetchData());
	}

	private function getListData($data, $config) {
		$ret = array();
		foreach ($data as $var) {
			foreach ($var as $key => $val) {
				$box = '<input type="checkbox" name="active[]" value="' . $key . '"';
				$box.= $this->isActive($key, $config);
				$box.='/>';
				$ret[] = array(
					'column1' => $box,
					'column2' => $key,
					'column3' => $val,
				);
			}
		}

		return $ret;
	}

	//check if domain is already in agent config file if so add 'checked' to checkbox
	private function isActive($domain, $config) {
		foreach ($config['domains'] as $dom => $dir) {
			if ($dom == $domain) {
				return ' checked ';
			}
		}

		return '';
	}

	private function buildDomainArray($domains) {
		$domainObj = array();

		foreach ($domains as $domain) {
			$dir = (string) $this->getDomainDir($domain);
			if ($dir != FALSE) {
				$domainObj[$domain] = $dir;
			}
		}

		return $domainObj;
	}

	//get htdocs dir for given domain from plesk api
	private function getDomainDir($domain) {
		$request = <<<DATA
<webspace>
	<get>
		<filter>
			<name>$domain</name>
		</filter>
		<dataset>
			<hosting/>
		</dataset>
	</get>
</webspace>	
DATA;

		$resp = pm_ApiRpc::getService()->call($request);

		foreach ($resp->webspace->get->result[0]->data->hosting->vrt_hst->property as $prop) {
			if ($prop->name == 'www_root') {
				return $prop->value;
			}
		}
		return false;
	}

	public function execAction() {
		$this->view->note = pm_Locale::lmsg('pleaseNote');
		$id = pm_Settings::get('agent-schedule-id');
		$cron_default = array(
			'minute' => '30',
			'hour' => '13',
			'dom' => '*',
			'month' => '*',
			'dow' => '*',
		);

		$form = new pm_Form_Simple();

		$status = 'inactive';
		if (!empty($id)) {

			$status = 'active';
		}
		$form->addElement('checkbox', 'status', array(
			'label' => 'Status (please check or uncheck to enable or disable the agent execution)',
			'value' => pm_Settings::get('agentStatus'),
		));
		$form->addElement('select', 'interval', array(
			'label' => 'Agent Scan Interval',
			'multiOptions' => array(
				'0' => pm_Locale::lmsg('once'),
				'12' => pm_Locale::lmsg('twice'),
				'8' => pm_Locale::lmsg('threeTimes'),
				'6' => pm_Locale::lmsg('fourTimes'),
			),
			'value' => pm_Settings::get('schedule-interval'),
			'required' => true,
		));
		$form->addControlButtons(array(
			'cancelLink' => pm_Context::getModulesListUrl(),
		));

		$task = new pm_Scheduler_Task();
		if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
			$cron = $cron_default;
			if ($form->getValue('interval') == '12') {
				$cron['hour'] = '1,13';
			} else if ($form->getValue('interval') == '8') {
				$cron['hour'] = '1,9,17';
			} else if ($form->getValue('interval') == '6') {
				$cron['hour'] = '1,7,13,19';
			}

			pm_Settings::set('agentStatus', $form->getValue('status'));

			if ($form->getValue('status') == '1') {
				try {
					if (!empty($id)) {
						$task = pm_Scheduler::getInstance()->getTaskById($id);
						pm_Scheduler::getInstance()->removeTask($task);
					}
				} catch (pm_Exception $e) {
					
				} finally {

					$task = new pm_Scheduler_Task();
					$task->setCmd('run.php');
					$task->setSchedule($cron);
					pm_Scheduler::getInstance()->putTask($task);

					pm_Settings::set('agent-schedule-id', $task->getId());
					pm_Settings::set('schedule-interval', $form->getValue('interval'));
					$this->_status->addMessage('info', 'agent successfully activated');
				}
			}

			if ($form->getValue('status') == '0') {
				if (!empty($id)) {
					try {
						$task = pm_Scheduler::getInstance()->getTaskById($id);
						pm_Scheduler::getInstance()->removeTask($task);

						pm_Settings::set('agent-schedule-id', FALSE);
					} catch (pm_Exception $e) {
						
					} finally {

						pm_Settings::set('agent-schedule-id', FALSE);
						$this->_status->addMessage('info', 'agent successfully deactivated');
					}
				}
			}
			 $this->_helper->json(array('redirect' => pm_Context::getActionUrl('index', 'exec')));
			
		}



		$this->view->form = $form;
	}

}
