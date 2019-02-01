<?php

	// https://developers.google.com/people/quickstart/php
	// https://developers.google.com/people/api/rest/v1/people/get
	require_once "vendor/autoload.php";

	// https://console.developers.google.com/start/api?id=people.googleapis.com&credential=client_key
	// clientId + clientSecret

	$config 	= [
	   	'client' => [
	   			'client_id'     	=> '<CLIENT_ID>',
				'client_secret' 	=> '<CLIENT_SECRET>',
				'redirect_uri'		=> '<URL_OF_THIS_SCRIPT>',
		   		'approval_prompt' 	=> 'force',
		   		'access_type' 		=> 'offline'
		],
		'scopes'       => [
				'https://www.googleapis.com/auth/userinfo.profile',
				'https://www.googleapis.com/auth/contacts',
				'https://www.googleapis.com/auth/contacts.readonly'
		]
	];

	// init
	$ga 			= new googleAddresses($config);
	$status 		= htmlspecialchars($_GET['status'] ?? null);
	$cli			= htmlspecialchars($_GET['cli'] ?? null);
	// update once a day...
	if($status == 'invite'
			&& $cli ){
		$q				= ltrim($cli,'+');
		$displayName 	= $ga->findContactByPhone($q);

		if($displayName)
			print	json_encode([
							'displayname'
									=> $displayName
			]);
	}



class googleAddresses{

	protected $clientConfig;
	protected $scopes;
	protected $client;
	protected $addressbook;

	public function __construct($config)
	{
		if(!is_array($config)
			|| !isset($config['client'])
			|| !isset($config['scopes']) )
		   	throw new \Exception('missing config!');

		$this->clientConfig 	= $config['client'];

		$this->scopes 			= $config['scopes'];

		$this->connect();

		$this->initAddressbook();
	}

	protected function getToken()
	{
		if(!is_file('auth.json'))
			return null;

		$storage = json_decode(file_get_contents(__DIR__.'/auth.json'), true);
		if (is_array($storage)) {
			return $storage;
		}
		return null;
	}

	protected function setValue($key, $value)
	{
		$storage       = [];
		if(is_file('auth.json'))
			$storage       = json_decode(file_get_contents(__DIR__.'/auth.json'), true);
		$storage[$key] = $value;
		file_put_contents(__DIR__.'/auth.json', json_encode($storage));
	}

	protected function connect()
	{
		//returned Auth;
		$this->client 	= new Google_Client($this->clientConfig);
		$this->client->setScopes($this->scopes);

		$token 			= $this->getToken();
		if(!isset($token['access_token'])){
			$token 		= $this->authorize();
		}
		$this->client->setAccessToken($token);

		if( $this->client->isAccessTokenExpired() ){
			$token 		= $this->client->fetchAccessTokenWithRefreshToken();
			$this->setValue('access_token',$token['access_token']);
			$this->setValue('access_token',$token['access_token']);
			$this->setValue('expires_in',$token['expires_in']);
			$this->setValue('id_token',$token['id_token']);
			$this->setValue('created',$token['created']);

		}

		return $token;
	}


	protected function authorize()
	{
		if(!isset($_REQUEST['code'])) {
			header('Location: ' . $this->client->createAuthUrl());
			exit;
		}

		$token 		= $this->client->fetchAccessTokenWithAuthCode($_REQUEST['code']);
		if(!isset($token['refresh_token']))
			throw new \Exception('No refresh token to be retrieved');

		$this->setValue('refresh_token',$token['refresh_token']);
		$this->setValue('access_token',$token['access_token']);
		$this->setValue('expires_in',$token['expires_in']);
		$this->setValue('id_token',$token['id_token']);
		$this->setValue('created',$token['created']);
		$this->setValue('scope',$token['scope']);


		die('ready');
	}


	function findContactByPhone($phone)
	{
		if(array_key_exists($phone,$this->addressbook))
			return $this->addressbook[$phone];
		return $phone;
	}

	function initAddressbook()
	{
		//TODO; http://php.net/manual/en/function.openssl-encrypt.php

		if(is_file('addressbook.json')){
			$data  	= json_decode(file_get_contents('addressbook.json'), true);
			if(isset($data['addressbook'])
					&& time() < $data['expire'] ){

				$this->addressbook = $data['addressbook'];
				return;
			}
		}

		$ab			= [];
		$service 	= new Google_Service_PeopleService($this->client);
		$optParams 	= [
				  'pageSize' => 2000,
				  'personFields' => 'names,phoneNumbers,organizations',
		];

		$results = $service->people_connections
								->listPeopleConnections('people/me', $optParams );
		if (count($results->getConnections()) == 0)
			return;

	  	foreach ($results->getConnections() as $person) {

		  /** @var Google_Service_People_Person $person */
			if (count($person->getNames()) == 0
				|| count($person->getPhoneNumbers()) == 0 )
				continue;

			$names 			= $person->getNames();
			$phoneNumbers 	= $person->getPhoneNumbers();
			$organisation 	= $person->getOrganizations();

			foreach($phoneNumbers as $phoneNumber){

				$orgName 			= null;
				if(isset($organisation[0]))
					$orgName		= $organisation[0]->getName(). ' - ';

				$phone 		= ltrim($phoneNumber->getCanonicalForm(),'+');
				$ab[$phone]	= $orgName.$names[0]->getDisplayName();
			}
		}

		$this->addressbook 		= $data['addressbook'] 	= $ab;
		$data['expire'] 		= (time() + 60*60*24);

		file_put_contents(__DIR__.'/addressbook.json', json_encode($data));
	}
}
