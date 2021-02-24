<?php

trait SSOFunctions
{
	public function getSSOUserFor($app, $userobj)
	{
		$map = array(
			'jellyfin' => 'username',
			'ombi' => 'username',
			'overseerr' => 'username',
			'tautulli' => 'username'
		);
		return (gettype($userobj) == 'string') ? $userobj : $userobj[$map[$app]];
	}
	
	public function ssoCheck($userobj, $password, $token = null)
	{
		if ($this->config['ssoPlex'] && $token) {
			$this->coookie('set', 'mpt', $token, $this->config['rememberMeDays'], false);
		}
		if ($this->config['ssoOmbi']) {
			$fallback = ($this->config['ombiFallbackUser'] !== '' && $this->config['ombiFallbackPassword'] !== '');
			$ombiToken = $this->getOmbiToken($this->getSSOUserFor('ombi', $userobj), $password, $token, $fallback);
			if ($ombiToken) {
				$this->coookie('set', 'Auth', $ombiToken, $this->config['rememberMeDays'], false);
			}
		}
		if ($this->config['ssoTautulli']) {
			$tautulliToken = $this->getTautulliToken($this->getSSOUserFor('tautulli', $userobj), $password, $token);
			if ($tautulliToken) {
				foreach ($tautulliToken as $key => $value) {
					$this->coookie('set', 'tautulli_token_' . $value['uuid'], $value['token'], $this->config['rememberMeDays'], true, $value['path']);
				}
			}
		}
		if ($this->config['ssoJellyfin']) {
			$jellyfinToken = $this->getJellyfinToken($this->getSSOUserFor('jellyfin', $userobj), $password);
			if ($jellyfinToken) {
				$this->coookie('set', 'jellyfin_credentials', $jellyfinToken, $this->config['rememberMeDays'], false);
			}
		}
		if ($this->config['ssoOverseerr']) {
			$overseerrToken = $this->getOverseerrToken($this->getSSOUserFor('overseerr', $userobj), $password, $token);
			if ($overseerrToken) {
				$this->coookie('set', 'connect.sid', $overseerrToken, $this->config['rememberMeDays'], false);
			}
		}
		return true;
	}
	
	public function getJellyfinToken($username, $password)
	{
		$token = null;
		try {
			$url = $this->qualifyURL($this->config['jellyfinURL']);
			$ssoUrl = $this->qualifyURL($this->config['jellyfinSSOURL']);
			$headers = array(
				'X-Emby-Authorization' => 'MediaBrowser Client="Organizr Jellyfin Tab", Device="Organizr_PHP", DeviceId="Organizr_SSO", Version="1.0"',
				"Accept" => "application/json",
				"Content-Type" => "application/json"
			);
			$data = array(
				"Username" => $username,
				"Pw" => $password
			);
			$endpoint = '/Users/authenticatebyname';
			$options = $this->requestOptions($url, false, 60);
			$response = Requests::post($url . $endpoint, $headers, json_encode($data), $options);
			if ($response->success) {
				$token = json_decode($response->body, true);
				$this->writeLog('success', 'Jellyfin Token Function - Grabbed token.', $username);
				return '{"Servers":[{"ManualAddress":"' . $ssoUrl . '","Id":"' . $token['ServerId'] . '","UserId":"' . $token['User']['Id'] . '","AccessToken":"' . $token['AccessToken'] . '"}]}';
			} else {
				$this->writeLog('error', 'Jellyfin Token Function - Jellyfin did not return Token', $username);
			}
		} catch (Requests_Exception $e) {
			$this->writeLog('error', 'Jellyfin Token Function - Error: ' . $e->getMessage(), $username);
		}
		return false;
	}
	
	public function getOmbiToken($username, $password, $oAuthToken = null, $fallback = false)
	{
		$token = null;
		try {
			$url = $this->qualifyURL($this->config['ombiURL']);
			$headers = array(
				"Accept" => "application/json",
				"Content-Type" => "application/json"
			);
			$data = array(
				"username" => ($oAuthToken ? "" : $username),
				"password" => ($oAuthToken ? "" : $password),
				"rememberMe" => "true",
				"plexToken" => $oAuthToken
			);
			$endpoint = ($oAuthToken) ? '/api/v1/Token/plextoken' : '/api/v1/Token';
			$options = $this->requestOptions($url, false, 60);
			$response = Requests::post($url . $endpoint, $headers, json_encode($data), $options);
			if ($response->success) {
				$token = json_decode($response->body, true)['access_token'];
				$this->writeLog('success', 'Ombi Token Function - Grabbed token.', $username);
			} else {
				if ($fallback) {
					$this->writeLog('error', 'Ombi Token Function - Ombi did not return Token - Will retry using fallback credentials', $username);
				} else {
					$this->writeLog('error', 'Ombi Token Function - Ombi did not return Token', $username);
				}
			}
		} catch (Requests_Exception $e) {
			$this->writeLog('error', 'Ombi Token Function - Error: ' . $e->getMessage(), $username);
		}
		if ($token) {
			return $token;
		} elseif ($fallback) {
			return $this->getOmbiToken($this->config['ombiFallbackUser'], $this->decrypt($this->config['ombiFallbackPassword']), null, false);
		} else {
			return false;
		}
	}
	
	public function getTautulliToken($username, $password, $plexToken = null)
	{
		$token = null;
		$tautulliURLList = explode(',', $this->config['tautulliURL']);
		if (count($tautulliURLList) !== 0) {
			foreach ($tautulliURLList as $key => $value) {
				try {
					$url = $this->qualifyURL($value);
					$headers = array(
						"Accept" => "application/json",
						"Content-Type" => "application/x-www-form-urlencoded",
						"User-Agent" => isset($_SERVER ['HTTP_USER_AGENT']) ? $_SERVER ['HTTP_USER_AGENT'] : null
					);
					$data = array(
						"username" => ($plexToken ? "" : $username),
						"password" => ($plexToken ? "" : $password),
						"token" => $plexToken,
						"remember_me" => 1,
					);
					$options = $this->requestOptions($url, false, 60);
					$response = Requests::post($url . '/auth/signin', $headers, $data, $options);
					if ($response->success) {
						$qualifiedURL = $this->qualifyURL($url, true);
						$path = ($qualifiedURL['path']) ? $qualifiedURL['path'] : '/';
						$token[$key]['token'] = json_decode($response->body, true)['token'];
						$token[$key]['uuid'] = json_decode($response->body, true)['uuid'];
						$token[$key]['path'] = $path;
						$this->writeLog('success', 'Tautulli Token Function - Grabbed token from: ' . $url, $username);
					} else {
						$this->writeLog('error', 'Tautulli Token Function - Error on URL: ' . $url, $username);
					}
				} catch (Requests_Exception $e) {
					$this->writeLog('error', 'Tautulli Token Function - Error: [' . $url . ']' . $e->getMessage(), $username);
				}
			}
		}
		return ($token) ? $token : false;
	}
	
	public function getOverseerrToken($username, $password, $oAuthToken = null, $fallback = false)
	{
		$token = null;
		try {
			$url = $this->qualifyURL($this->config['overseerrURL']);
			$headers = array(
				"Content-Type" => "application/json"
			);
			$data = array(
				//"username" => ($oAuthToken ? "" : $username), // not needed yet
				//"password" => ($oAuthToken ? "" : $password), // not needed yet
				"authToken" => $oAuthToken
			);
			$endpoint = '/api/v1/auth/login';
			$options = $this->requestOptions($url, false, 60);
			$response = Requests::post($url . $endpoint, $headers, json_encode($data), $options);
			if ($response->success) {
				$user = json_decode($response->body, true); // not really needed yet
				$token = $response->cookies['connect.sid']->value;
				$this->writeLog('success', 'Overseerr Token Function - Grabbed token', $username);
			} else {
				if ($fallback) {
					$this->writeLog('error', 'Overseerr Token Function - Overseerr did not return Token - Will retry using fallback credentials', $username);
				} else {
					$this->writeLog('error', 'Overseerr Token Function - Overseerr did not return Token', $username);
				}
			}
		} catch (Requests_Exception $e) {
			$this->writeLog('error', 'Overseerr Token Function - Error: ' . $e->getMessage(), $username);
		}
		if ($token) {
			return urldecode($token);
		} elseif ($fallback) {
			return $this->getOverseerrToken($this->config['overseerrFallbackUser'], $this->decrypt($this->config['overseerrFallbackPassword']), null, false);
		} else {
			return false;
		}
	}
	
}
