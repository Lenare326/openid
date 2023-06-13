<?php

$loader = require('plugins/generic/openid/vendor/autoload.php');

use Firebase\JWT\JWT;
use GuzzleHttp\Exception\GuzzleException;
use phpseclib\Crypt\RSA;
use phpseclib\Math\BigInteger;

import('classes.handler.Handler');

/**
 * This file is part of OpenID Authentication Plugin (https://github.com/leibniz-psychology/pkp-openid).
 *
 * OpenID Authentication Plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * OpenID Authentication Plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OpenID Authentication Plugin.  If not, see <https://www.gnu.org/licenses/>.
 *
 * Copyright (c) 2020 Leibniz Institute for Psychology Information (https://leibniz-psychology.org/)
 *
 * @file plugins/generic/openid/handler/OpenIDHandler.inc.php
 * @ingroup plugins_generic_openid
 * @brief Handler for OpenID workflow:
 *  - receive auth-code
 *  - perform auth-code -> token exchange
 *  - token validation via server certificate
 *  - extract user details
 *  - register new accounts
 *  - connect existing accounts
 *
 *
 */
 
 
define("ORCID_BASE_URL", "https://sandbox.orcid.org/");

class OpenIDHandler extends Handler
{
	function doMicrosoftAuthentication($args, $request)
	{
		return $this->doAuthentication($args, $request, 'microsoft');
	}

	/**
	 * This function is called via OpenID provider redirect URL.
	 * It receives the authentication code via the get parameter and uses $this->_getTokenViaAuthCode to exchange the code into a JWT
	 * The JWT is validated with the public key of the server fetched by $this->_getOpenIDAuthenticationCert.
	 * If the JWT and the key are successfully retrieved, the JWT is validated and extracted using $this->_validateAndExtractToken.
	 *
	 * If no user was found with the provided OpenID identifier a second step is called to connect a local account with the OpenID account, or to register a
	 * new OJS account. It is possible for a user to connect his/her OJS account to more than one OpenID provider.
	 *
	 * If the OJS account is disabled or in case of errors/exceptions the user is redirect to the sign in page and some errors will be displayed.
	 *
	 * @param $args
	 * @param $request
	 * @return bool
	 */
	function doAuthentication($args, $request, $provider = null)
	{	
		$context = $request->getContext();
		$plugin = PluginRegistry::getPlugin('generic', KEYCLOAK_PLUGIN_NAME);
		$contextId = ($context == null) ? 0 : $context->getId();
		$settings = json_decode($plugin->getSetting($contextId, 'openIDSettings'), true);
		$selectedProvider = $provider == null ? $request->getUserVar('provider') : $provider;
		
		// prepare token payload if NOT shib
		if($selectedProvider != 'shibboleth'){
			$token = $this->_getTokenViaAuthCode($settings['provider'], $request->getUserVar('code'), $selectedProvider);
			$publicKey = $this->_getOpenIDAuthenticationCert($settings['provider'], $selectedProvider);
			
			if (isset($token) && isset($publicKey)) {
				$tokenPayload = $this->_validateAndExtractToken($token, $publicKey);

				if (isset($tokenPayload) && is_array($tokenPayload)) {
					$tokenPayload['selectedProvider'] = $selectedProvider;
				} 
			} 
			else {
				$ssoErrors['sso_error'] = !isset($publicKey) ? 'connect_key' : 'connect_data';
			}
		}
		
		// prepare token payload if provider is SHIB
		else if($selectedProvider == 'shibboleth'){
			$tmpProviderList = $settings['provider'];
			$shibProviderSettings =  $tmpProviderList['shibboleth'];
			
			$uinHeader = $shibProviderSettings['shibbolethHeaderUin'];
			$emailHeader = $shibProviderSettings['shibbolethHeaderEmail'];
			$givenNameHeader = $shibProviderSettings['shibbolethHeaderFirstName'];
			$familyNameHeader = $shibProviderSettings['shibbolethHeaderLastName'];
			$orcidHeader = $shibProviderSettings['shibbolethHeaderOrcid'];
			$accessTokenHeader = $shibProviderSettings['shibbolethHeaderAccessToken'];
			
			
			// check for required headers
			if (!isset($_SERVER[$uinHeader])) {
			error_log(
				"Shibboleth provider enabled, but not properly configured; failed to find $uinHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
			}
			
			if (!isset($_SERVER[$emailHeader])) {
			error_log(
				"Shibboleth provider enabled, but not properly configured; failed to find $emailHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
			}
			
			if (!isset($_SERVER[$givenNameHeader])) {
			error_log(
				"Shibboleth provider enabled, but not properly configured; failed to find $givenNameHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
			}
			
			if (!isset($_SERVER[$familyNameHeader])) {
			error_log(
				"Shibboleth provider enabled, but not properly configured; failed to find $familyNameHeader"
			);
			Validation::logout();
			Validation::redirectLogin();
			return false;
			}
					
			$uin = $_SERVER[$uinHeader];
			$userEmail = $_SERVER[$emailHeader];
			$userGivenName = $_SERVER[$givenNameHeader];
			$userFamilyName = $_SERVER[$familyNameHeader];
			$userOrcidUrl = (!empty($orcidHeader) && isset($_SERVER[$orcidHeader]))? $_SERVER[$orcidHeader] : null;
			
			// extract the data from the Access Token Attribute if present
			$userAccessData = (!empty($accessTokenHeader) && isset($_SERVER[$accessTokenHeader]))? $_SERVER[$accessTokenHeader] : null;
			$userAccessToken = null;
			$userAccessScope = null;
			$userAccessExpiresOn = null;
			
			if(isset($userAccessData)){
				$extractedAccessData = self::extractShibTokenData($userAccessData);
				$userAccessToken = $extractedAccessData['token'];
				$userAccessExpiresOn = $extractedAccessData['expires'];
				$userAccessScope = $extractedAccessData['scope'];
			}
			
			$providerSettingsId = $uin; // the value that will go into openid::shibboleth, default = UIN
			
			// prepare payload for shibboleth
			$tokenPayload = [
				'selectedProvider' => $selectedProvider,
				'id' => $providerSettingsId,
				'email' => isset($userEmail) ? $userEmail : null,
				'username' => null,
				'given_name' => isset($userGivenName) ? $userGivenName : null,
				'family_name' => isset($userFamilyName) ? $userFamilyName : null,
				'email_verified' => null,
				'orcid' =>  isset($userOrcidUrl) ? $userOrcidUrl : null,
				'access_token' => isset($userAccessToken) ? $userAccessToken : null,
				'scope' => isset($userAccessScope) ? $userAccessScope : null,
				'expires_in' => isset($userAccessExpiresOn) ? $userAccessExpiresOn : null,
			];
		
		}
		

		$user = $this->_getUserViaKeycloakId($tokenPayload);
		if (!isset($user)) {
			import($plugin->getPluginPath().'/forms/OpenIDStep2Form');
			
			$regForm = new OpenIDStep2Form($plugin, $tokenPayload);
			$regForm->initData();

			return $regForm->fetch($request, null, true);
		} 
		
		elseif (is_a($user, 'User') && !$user->getDisabled()) {
			Validation::registerUserSession($user, $reason, true);

			self::updateUserDetails($tokenPayload, $user, $request, $selectedProvider, false);
			if ($user->hasRole(
				[ROLE_ID_SITE_ADMIN, ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_AUTHOR, ROLE_ID_REVIEWER, ROLE_ID_ASSISTANT],
				$contextId
			)) {
				return $request->redirect($context, 'submissions');
			} else {
				return $request->redirect($context, 'user', 'profile', null, $args);
			}
		} 
		
		elseif ($user->getDisabled()) {
			$reason = $user->getDisabledReason();
			$ssoErrors['sso_error'] = 'disabled';
			if ($reason != null) {
				$ssoErrors['sso_error_msg'] = $reason;
			}
		}
		
		return $request->redirect($context, 'login', null, null, isset($ssoErrors) ? $ssoErrors : null);

	}


	/**
	 * Step2 POST (Form submit) function.
	 * OpenIDStep2Form is used to handle form initialization, validation and persistence.
	 *
	 * @param $args
	 * @param $request
	 */
	function registerOrConnect($args, $request)
	{
		$context = $request->getContext();

		if (Validation::isLoggedIn()) {
			$this->setupTemplate($request);
			$templateMgr = TemplateManager::getManager($request);
			$templateMgr->assign('pageTitle', 'user.login.registrationComplete');
			$templateMgr->display('frontend/pages/userRegisterComplete.tpl');
		} elseif (!$request->isPost()) {
			$request->redirect($context, 'login');
		} else {
			$plugin = PluginRegistry::getPlugin('generic', KEYCLOAK_PLUGIN_NAME);
			import($plugin->getPluginPath().'/forms/OpenIDStep2Form');
			$regForm = new OpenIDStep2Form($plugin);
			$regForm->readInputData();
			if (!$regForm->validate()) {
				$regForm->display($request);
			} elseif ($regForm->execute()) {
				$request->redirect($context, 'openid', 'registerOrConnect');
			} else {
				$regForm->addError('', '');
				$regForm->display($request);
			}
		}
	}

	public static function updateUserDetails($payload, $user, $request, $selectedProvider, $setProviderId = false)
	{	
		$userDao = DAORegistry::getDAO('UserDAO');
		$context = $request->getContext();
		$contextId = ($context == null) ? 0 : $context->getId();
		$plugin = PluginRegistry::getPlugin('generic', KEYCLOAK_PLUGIN_NAME);
		$settings = json_decode($plugin->getSetting($contextId, 'openIDSettings'), true);
		

		if (key_exists('providerSync', $settings) && $settings['providerSync'] == 1) {
			$site = $request->getSite();
			$sitePrimaryLocale = $site->getPrimaryLocale();
			$currentLocale = AppLocale::getLocale();
			if (is_array($payload) && key_exists('given_name', $payload) && !empty($payload['given_name'])) {
				$user->setGivenName($payload['given_name'], ($sitePrimaryLocale != $currentLocale) ? $sitePrimaryLocale : $currentLocale);
			}
			if (is_array($payload) && key_exists('family_name', $payload) && !empty($payload['family_name'])) {
				$user->setFamilyName($payload['family_name'], ($sitePrimaryLocale != $currentLocale) ? $sitePrimaryLocale : $currentLocale);
			}
			if (is_array($payload) && key_exists('email', $payload) && !empty($payload['email']) && $userDao->getUserByEmail($payload['email']) == null) {
				$user->setEmail($payload['email']);
			}
			$userDao->updateObject($user);
		}
		
		// update orcid fields (moved outside of 'providerSync' clause)
		if ($selectedProvider == 'orcid' || $selectedProvider == 'shibboleth') {
				
				if (is_array($payload) && key_exists('orcid', $payload)) {
					
					// save orcid id, acces token, token expiration and scope (the latter 3 only if available)
						self::addOrcidPluginFields($user, $payload);
				}
			}

		$userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
		$userSettingsDao->updateSetting($user->getId(), 'openid::lastProvider', $selectedProvider, 'string');

		if (is_array($payload) && key_exists('id', $payload) && !empty($payload['id'])) {
			if ($setProviderId) {
				$userSettingsDao->updateSetting($user->getId(), 'openid::'.$selectedProvider, $payload['id'], 'string');
			}
			$generateApiKey = isset($settings) && key_exists('generateAPIKey', $settings) ? $settings['generateAPIKey'] : false;
			$secret = Config::getVar('security', 'api_key_secret', '');
			if ($generateApiKey && $selectedProvider == 'custom' && $secret) {
				$user->setData('apiKeyEnabled', true);
				$user->setData('apiKey', self::encryptOrDecrypt($plugin, $contextId, 'encrypt', $payload['id']));
				$userDao->updateObject($user);
			}
		}
	}
	


	/**
	 * De-/Encrypt function to hide some important things.
	 *
	 * @param $plugin
	 * @param $contextId
	 * @param $action
	 * @param $string
	 * @return string|null
	 */
	public static function encryptOrDecrypt($plugin, $contextId, $action, $string)
	{
		$alg = 'AES-256-CBC';
		$settings = json_decode($plugin->getSetting($contextId, 'openIDSettings'), true);
		$result = null;

		if (key_exists('hashSecret', $settings) && !empty($settings['hashSecret'])) {
			$pwd = $settings['hashSecret'];
			$iv = substr($settings['hashSecret'], 0, 16);
			if ($action == 'encrypt') {
				$result = openssl_encrypt($string, $alg, $pwd, 0, $iv);
			} elseif ($action == 'decrypt') {
				$result = openssl_decrypt($string, $alg, $pwd, 0, $iv);
			}
		} else {
			$result = $string;
		}

		return $result;
	}

	/**
	 * Tries to find a user via OpenID credentials via user settings openid::{provider}
	 * This is a very simple step, and it should be safe because the token is valid at this point.
	 * If the token is invalid, the auth process stops before this function is called.
	 *
	 * @param array $credentials
	 * @return User|null
	 */
	private function _getUserViaKeycloakId(array $credentials)
	{
		$userDao = DAORegistry::getDAO('UserDAO');
		$user = $userDao->getBySetting('openid::'.$credentials['selectedProvider'], $credentials['id']);
		if (isset($user) && is_a($user, 'User')) {
			return $user;
		}
		// prior versions of this plugin used hash for saving the openid identifier, but this is not recommended.
		$user = $userDao->getBySetting('openid::'.$credentials['selectedProvider'], hash('sha256', $credentials['id']));
		if (isset($user) && is_a($user, 'User')) {
			return $user;
		}

		return null;
	}


	/**
	 * This function swaps the Auth code into a JWT that contains the user_details and a signature.
	 * An array with the access_token, id_token and/or refresh_token is returned on success, otherwise null.
	 * The OpenID implementation differs a bit between the providers. Some use an id_token, others a refresh token.
	 *
	 * @param array $providerList
	 * @param string $authorizationCode
	 * @param string $selectedProvider
	 * @return array
	 */
	private function _getTokenViaAuthCode(array $providerList, string $authorizationCode, string $selectedProvider)
	{
		$token = null;
		if (isset($providerList) && key_exists($selectedProvider, $providerList)) {
			$settings = $providerList[$selectedProvider];
			$httpClient = Application::get()->getHttpClient();
			$response = null;
			$params = [
				'code' => $authorizationCode,
				'grant_type' => 'authorization_code',
				'client_id' => $settings['clientId'],
				'client_secret' => $settings['clientSecret'],
			];
			if ($selectedProvider != 'microsoft') {
				$params['redirect_uri'] = Application::get()->getRequest()->url(
					null,
					'openid',
					'doAuthentication',
					null,
					array('provider' => $selectedProvider)
				);
			}
			try {
				$response = $httpClient->request(
					'POST',
					$settings['tokenUrl'],
					[
						'headers' => [
							'Accept' => 'application/json',
						],
						'form_params' => $params,
					]
				);
				if ($response->getStatusCode() != 200) {
					error_log('Guzzle Response != 200: '.$response->getStatusCode());
				} else {
					$result = $response->getBody()->getContents();
					if (isset($result) && !empty($result)) {
						$result = json_decode($result, true);
						if (is_array($result) && !empty($result) && key_exists('access_token', $result)) {
							$token = [
								'access_token' => $result['access_token'],
								'scope' => key_exists('scope', $result) ? $result['scope'] : null,
								'expires_in' => key_exists('expires_in', $result) ? $result['expires_in'] : null,
								'id_token' => key_exists('id_token', $result) ? $result['id_token'] : null,
								'refresh_token' => key_exists('refresh_token', $result) ? $result['refresh_token'] : null,
							];
						}
					}
				}
			} catch (GuzzleException $e) {
				error_log('Guzzle Exception thrown: '.$e->getMessage());
			}
		}

		return $token;
	}

	/**
	 * This function uses the certs endpoint of the openid provider to get the server certificate.
	 * There are provider-specific differences in case of the certificate.
	 *
	 * E.g.
	 * - Keycloak uses x5c as certificate format which included the cert.
	 * - Other vendors provide the cert modulus and exponent and the cert has to be created via phpseclib/RSA
	 *
	 * If no key is found, null is returned
	 *
	 * @param array $providerList
	 * @param string $selectedProvider
	 * @return array
	 */
	private function _getOpenIDAuthenticationCert(array $providerList, string $selectedProvider)
	{
		$publicKeys = null;
		if (isset($providerList) && key_exists($selectedProvider, $providerList)) {
			$settings = $providerList[$selectedProvider];
			$beginCert = '-----BEGIN CERTIFICATE-----';
			$endCert = '-----END CERTIFICATE----- ';
			$httpClient = Application::get()->getHttpClient();
			$response = null;
			try {
				$response = $httpClient->request('GET', $settings['certUrl']);
				if ($response->getStatusCode() != 200) {
					error_log('Guzzle Response != 200: '.$response->getStatusCode());
				} else {
					$result = $response->getBody()->getContents();
					$arr = json_decode($result, true);
					if (key_exists('keys', $arr)) {
						$publicKeys = array();
						foreach ($arr['keys'] as $key) {
							if ((key_exists('alg', $key) && $key['alg'] = 'RS256') || (key_exists('kty', $key) && $key['kty'] = 'RSA')) {
								if (key_exists('x5c', $key) && $key['x5c'] != null && is_array($key['x5c'])) {
									foreach ($key['x5c'] as $n) {
										if (!empty($n)) {
											$publicKeys[] = $beginCert.PHP_EOL.$n.PHP_EOL.$endCert;
										}
									}
								} elseif (key_exists('n', $key) && key_exists('e', $key)) {
									$rsa = new RSA();
									$modulus = new BigInteger(JWT::urlsafeB64Decode($key['n']), 256);
									$exponent = new BigInteger(JWT::urlsafeB64Decode($key['e']), 256);
									$rsa->loadKey(array('n' => $modulus, 'e' => $exponent));
									$publicKeys[] = $rsa->getPublicKey();
								}
							}
						}
					}
				}
			} catch (GuzzleException $e) {
				error_log('Guzzle Exception thrown: '.$e->getMessage());
			}
		}

		return $publicKeys;
	}

	/**
	 * Validates the token via JWT and public key and returns the token payload data as array.
	 * In case of an error null is returned
	 *
	 * @param array $token
	 * @param array $publicKeys
	 * @return array|null
	 */
	private function _validateAndExtractToken(array $token, array $publicKeys)
	{		
		$credentials = null;
		$userAccessToken = isset($token['access_token']) ?  $token['access_token'] : null;
		
		// add additional keys for Orcid Provider to enable interoperability with Orcid Profile plugin
		$userOrcidScope = isset($token['scope']) ? $token['scope'] : null;
		$accessTokenExpiration = isset($token['expires_in']) ? $token['expires_in'] : null;
		
		foreach ($publicKeys as $publicKey) {
			foreach ($token as $t) {
				try {
					if (!empty($t)) {
						$jwtPayload = JWT::decode($t, $publicKey, array('RS256'));

						if (isset($jwtPayload)) {
							$orcidId = null;
							if(property_exists($jwtPayload, 'sub') && preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}/',$jwtPayload->sub)){
								$orcidId = $jwtPayload->sub;
								$orcidIdUrl = ORCID_BASE_URL.$orcidId;
							}
							$credentials = [
								'id' => property_exists($jwtPayload, 'sub') ? (preg_match('/^\d{4}-\d{4}-\d{4}-\d{4}/',$jwtPayload->sub)? ORCID_BASE_URL.($jwtPayload->sub) : $jwtPayload->sub) : null,
								'email' => property_exists($jwtPayload, 'email') ? $jwtPayload->email : null,
								'username' => property_exists($jwtPayload, 'preferred_username') ? $jwtPayload->preferred_username : null,
								'given_name' => property_exists($jwtPayload, 'given_name') ? $jwtPayload->given_name : null,
								'family_name' => property_exists($jwtPayload, 'family_name') ? $jwtPayload->family_name : null,
								'email_verified' => property_exists($jwtPayload, 'email_verified') ? $jwtPayload->email_verified : null,
								'orcid' => $orcidIdUrl,
								'access_token' => $userAccessToken,
								'scope' => $userOrcidScope,
								'expires_in' => $accessTokenExpiration,
								
							];
						}
						if (isset($credentials) && key_exists('id', $credentials) && !empty($credentials['id'])) {
							break 2;
						}
					}
				} catch (Exception $e) {
					$credentials = null;
				}
			}
		}

		return $credentials;
	}

	/**
	 * This function is unused at the moment.
	 * It can be unsed to get the user details from an endpoint but usually all user data are provided in the JWT.
	 *
	 * @param $token
	 * @param $settings
	 *
	 * @return bool|string
	 */
	private function _getClientDetails($token, $settings)
	{
		$httpClient = Application::get()->getHttpClient();
		$response = null;
		$result = null;
		try {
			$response = $httpClient->request(
				'GET',
				$settings['userInfoUrl'],
				[
					'headers' => [
						'Accept' => 'application/json',
						'Authorization' => 'Bearer '.$token['access_token'],
					],
				]
			);
			if ($response->getStatusCode() != 200) {
				error_log('Guzzle Response != 200: '.$response->getStatusCode());
			} else {
				$result = $response->getBody()->getContents();
			}
		} catch (GuzzleException $e) {
			error_log('Guzzle Exception thrown: '.$e->getMessage());
		}

		return $result;
	}
	
	
	/** Get the status of the Orcid Profile Plugin
	* @return int isEnabled
	* Currently UNUSED
	*/
	function orcidEnabled() {
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
		$orcidPluginName = "OrcidProfilePlugin";
		$settingName="enabled";
		
		$_plugin = PluginRegistry::getPlugin('generic', KEYCLOAK_PLUGIN_NAME);
		$context = $_plugin->getCurrentContextId();
		
		$isEnabled = $pluginSettingsDao->getSetting($context, $orcidPluginName, $settingName);

		return (int) $isEnabled; 
	}
	
	
	/**
	* stores and handles orcid id, access token, scope, and token expiration date
	* adding these fields improves compatibility with Orcid Plugin functions
	*/
	public static function addOrcidPluginFields($user, $payload){
		$userDao = DAORegistry::getDAO('UserDAO');
		$userSettingsDao = DAORegistry::getDAO('UserSettingsDAO');
		$username = $user->getData('username');
		
		$userAccessToken = key_exists('access_token', $payload) ? $payload['access_token'] : null;
		$userOrcidScope = key_exists('scope', $payload) ? $payload['scope'] : null;
		$accessTokenExpiration = key_exists('expires_in', $payload) ? $payload['expires_in'] : null;
		
		$orcidIdUrl = key_exists('orcid', $payload) ? $payload['orcid'] : null;
		if(!(empty($orcidIdUrl))) {
			// get ORCID iD from DB (needs to be explicitly set to null, otherwise logic is not correct)
			$orcidStoredInDB = empty($user->getData('orcid')) ? null : $user->getData('orcid');
			if(empty($orcidStoredInDB) || ($orcidStoredInDB != $orcidIdUrl)){
				
				// in any case, set the ORCID iD (e.g. if Shib delivers only ORCID iD but no token etc)
				$user->setData('orcid', $orcidIdUrl);
				$userDao->updateObject($user);
				syslog(LOG_INFO, "ORCID iD stored/updated for user $username.");
			}
		
			if(!empty($userAccessToken) && !empty($userOrcidScope) && !empty($accessTokenExpiration)) {	
				// convert expiration date (delivered with oauth) to date in format yyyy-mm-dd
				$accessTokenExpiration=Date('Y-m-d', strtotime('+'.$accessTokenExpiration. 'seconds'));

				// try get stored token expiration date from DB for comparison
				$storedExpDate = $user->getData('orcidAccessExpiresOn');
				$accessExpiredDate = empty($storedExpDate) ? null : date_create($storedExpDate);
				$today = date_create(date('Y-m-d'));
				
				$scopeStoredInDB = $user->getData('orcidAccessScope');
				$tokenStoredInDB = $user->getData('orcidAccessToken');
				
				// CONDITIONS OF WHEN TO UPDATE ORCID FIELDS (no entry yet, different ORCID iD, expired token, different token)
				// updates on almost every login since the token is always freshly created (but this is the only way to catch IDs previously saved with the Orcid Plugin)
				// TODO: maybe simplify and always update instead of checking conditions
				$newEntry = (empty($orcidStoredInDB) || empty($storedExpDate));
				$overwriteEntry = (!empty($accessExpiredDate) && ($today > $accessExpiredDate) || ($orcidStoredInDB != $orcidIdUrl) || ($scopeStoredInDB != $userOrcidScope) || ($tokenStoredInDB != $userAccessToken));


				if($newEntry || $overwriteEntry){
					
		
					$user->setData('orcid', $orcidIdUrl);
					$user->setData('orcidAccessToken', $userAccessToken);
					$user->setData('orcidAccessScope', $userOrcidScope);
					$user->setData('orcidAccessExpiresOn', $accessTokenExpiration);
					
					// if Orcid iD was stored previously via Orcid Plugin, remove the refresh token after overwriting with new data
					// TODO: can be adpated once the refresh token will be delivered via OpenID and Shibboleth
					if(!empty($user->getData('orcidRefreshToken'))){
						$user->setData('orcidRefreshToken', null);
					}


					syslog(LOG_INFO, "Orcid fields updated for entry $username");
				}
				

				else {
					syslog(LOG_INFO, "Already stored an ORCID iD with a valid token for user $username, not overwriting.");
				}
				
				$userDao->updateObject($user);
				
			}
			
			
			else {
				syslog(LOG_NOTICE, "OpenIDHandler did not save additional ORCID data (token, scope, expiry). Fields empty!");
			}
		}
		else{
			syslog(LOG_NOTICE, "ORCID iD was empty for user $username! This is intended if the Orcid Header is not configured or user has not connected their ORCID iD within Shibboleth Application.");
		}
	}
	
	/**
	* Helper function to ectract data from the Shib AccessToken Header
	*/
	public static function extractShibTokenData($accessTokenData){
		$splitVals = explode('$', $accessTokenData);
		$token = $splitVals[0];
		$expiration = $splitVals[1];
		$scope = $splitVals[2];
		
		$extractedData = [
							'token' => $token, 
							'expires' => $expiration, 
							'scope' => $scope
						];
		return $extractedData;
	}
	
}