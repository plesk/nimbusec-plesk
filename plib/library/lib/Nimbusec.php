<?php

/**
 * Nimbusec Helper Class
 * 
 * All public method may throw NimbusecExceptions that have to be caught in the IndexController since there they can be added to the appropriate status messages
 */
class Modules_NimbusecAgentIntegration_Lib_Nimbusec {

	private $key = '';
	private $secret = '';
	private $server = '';

	public function __construct() {
		pm_Context::init('nimbusec-agent-integration');

		//read necessary properties from key-value-store and store them into class variables
		$this->key = pm_Settings::get('apikey');
		$this->secret = pm_Settings::get('apisecret');
		$this->server = pm_Settings::get('apiserver');
	}

	/**
	 * check if given domain exists. if not create new domain in given bundle
	 * @param string $domain domain to be checked/created
	 * @param string $bundle if domain needs to be created add it to this bundle
	 * @return boolean true if domain already exists or was created. if false an error occured during creation
	 */
	public function checkDomain($domain, $bundle) {
		require_once 'NimbusecAPI.php';
		$api = new NimbusecAPI($this->key, $this->secret, $this->server);
		$domains = $api->findDomains("name=\"$domain\"");

		if (count($domains) == 1) {
			return true;
		} else if (count($domains) == 0) {
			//crete domain
			$scheme = "http";

			$domain = array(
				"scheme" => $scheme,
				"name" => $domain,
				"deepScan" => $scheme . '://' . $domain,
				"fastScans" => array(
					$scheme . '://' . $domain
				),
				"bundle" => $bundle
			);
			//return true if create == success

			$api->createDomain($domain);

			return true;
		}

		return false;
	}

	/**
	 * query all active bundles from the api
	 * @return array list of all active bundles
	 */
	public function getBundles() {
		require_once 'NimbusecAPI.php';
		$api = new NimbusecAPI($this->key, $this->secret, $this->server);
		$bundles = $api->findBundles();
		

		return $bundles;
	}

	/**
	 * create a new token for the server agent
	 * @param string $name name for the new server agent token
	 * @return array array/object with data of the created token
	 */
	public function getAgentCredentials($name) {
		require_once 'NimbusecAPI.php';
		$api = new NimbusecAPI($this->key, $this->secret, $this->server);
		
		$storedToken = $api->findAgentToken("name=\"$name\"");
		
		if (count($storedToken) > 0) {
			return $storedToken[0];
		} else {
			$token = array(
				'name' => (string) $name,
			);

			return $api->createAgentToken($token);
		}
		
		
	}
	
	/**
	 * download the agent from the API and unpack it into the given directory
	 * @param string $path path into which the downloaded agent should be extracted
	 * @return boolean indicates whether extracting the token worked
	 */
	public function fetchAgent($path) {
		require_once 'NimbusecAPI.php';
		$api = new NimbusecAPI($this->key, $this->secret, $this->server);
		
		$os = strtolower(PHP_OS);
		
		if ($os == 'winnt' || $os == 'win32') {
			$os = 'windows';
		}
		
		$arch = (string)8*PHP_INT_SIZE;
		$arch.='bit';
		
		
		$agents = $api->findServerAgents("os=\"$os\" and arch=\"$arch\"");
		
		if (count($agents) > 0) {
			$agentBin = $api->findSpecificServerAgent($agents[0]['os'], $agents[0]['arch'], $agents[0]['version'], 'bin');

			
			$name = 'agent';
			if ($os == 'windows') {
				$name=$name.'.exe';
			}
			file_put_contents($path.$name, $agentBin);
			
			if ($os!='windows') {
				chmod($path.$name, 0755);
			}
			
			return true;
		}
		return false;
	}
}
