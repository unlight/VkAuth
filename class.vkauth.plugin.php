<?php if (!defined('APPLICATION')) die();

$PluginInfo['VkAuth'] = array(
	'Name' => 'Vkontakte Authentication',
	'Description' => 'Vkontakte authentication for Garden. This is modified Facebook Connect plugin.',
	'Version' => '1.30',
	'MobileFriendly' => True,
	'SettingsUrl' => '/dashboard/settings/vkauth',
	'SettingsPermission' => 'Garden.Settings.Manage'
);

class VkAuthPlugin extends Gdn_Plugin {
	
	public $PopupWidth = 600;
	public $PopupHeight = 250;
    
	public function AuthenticationController_Render_Before($Sender, $Args) {
		if (isset($Sender->ChooserList)) $Sender->ChooserList['VkAuth'] = 'VkAuth';
		
        if (is_array($Sender->Data('AuthenticationConfigureList'))) {
            $List = $Sender->Data('AuthenticationConfigureList');
            $List['vkauth'] = '/dashboard/settings/vkauth';
            $Sender->SetData('AuthenticationConfigureList', $List);
		}
	}

	protected function AuthorizeUri($Query = FALSE) {
		$AppID = C('Plugins.VkAuth.ApplicationID');
		$RedirectUri = $this->RedirectUri();
		if ($Query) $RedirectUri .= '&'.$Query;
		$RedirectUri = urlencode($RedirectUri);
		$SigninHref = "http://api.vkontakte.ru/oauth/authorize?client_id={$AppID}&scope=&redirect_uri=$RedirectUri&response_type=code";
		if ($Query) $SigninHref .= '&'.$Query;
		return $SigninHref;
	}
	
	
	protected function SignInHtml($Img = '') {
		$SigninHref = $this->AuthorizeUri();
		$PopupSigninHref = $this->AuthorizeUri('display=popup');
		$Attributes = array(
			'id' => 'VkAuth',
			'href' => $SigninHref,
			'class' => 'PopupWindow',
			'popupHref' => $PopupSigninHref,
			'popupHeight' => $this->PopupHeight,
			'popupWidth' => $this->PopupWidth
		);
		$Attributes = Attribute($Attributes);
		$SignInHtml = "<a{$Attributes}>{$Img}</a>";
		return $SignInHtml;
	}
	
	public function EntryController_SignIn_Handler($Sender, $Args) {
        if (!$this->IsConfigured()) return;
		if (isset($Sender->Data['Methods'])) {
			$Img = Img('plugins/VkAuth/design/vkontakte-login.png', array('alt' => T('Login with Vkontakte')));
			$VkMethod = array('Name' => 'VkAuth', 'SignInHtml' => $this->SignInHtml($Img));
			$Sender->Data['Methods'][] = $VkMethod;
		}
	}

	public function Base_BeforeSignInButton_Handler($Sender, $Args) {
		if (!$this->IsConfigured()) return;
		echo "\n", $this->_GetButton();
	}
	
	public function Base_BeforeSignInLink_Handler($Sender) {
		if (!$this->IsConfigured()) return;
		if (!Gdn::Session()->IsValid())
			echo "\n", Wrap($this->_GetButton(), 'li', array('class' => 'Connect VkAuthConnect'));
	}
	
	private function _GetButton() {
		$Img = Img('plugins/VkAuth/design/vk-icon.png', array('alt' => T('Login with Vkontakte'), 'align' => 'bottom'));
		$Result = $this->SignInHtml($Img);
		return $Result;
	}
	
	protected function AccessToken() {
		$Token = GetValue('vk_access_token', $_COOKIE);
		return $Token;
	}

	protected function Authorize($Query = FALSE) {
		$Uri = $this->AuthorizeUri($Query);
		Redirect($Uri);
	}
	
	protected function UserID() {
		$UserID = GetValue('vk_user_id', $_COOKIE);
		return $UserID;
	}

	// Excecutes in try {} block we can throw exception anywhere
	public function Base_ConnectData_Handler($Sender, $Args) {
		if (GetValue(0, $Args) != 'vkauth') return;

		if (isset($_GET['error'])) throw new Gdn_UserException($_GET['error']);
		
		$AppID = C('Plugins.VkAuth.ApplicationID');
		$Secret = C('Plugins.VkAuth.Secret');
		$Code = GetValue('code', $_GET);
		$Query = '';
		if ($Sender->Request->Get('display')) $Query = 'display='.urlencode($Sender->Request->Get('display'));

		$RedirectUri = ConcatSep('&', $this->RedirectUri(), $Query);
		$RedirectUri = urlencode($RedirectUri);
		
		$Tokens = False;
		// Get the access token.
		$AccessToken = $this->AccessToken();
		$VkUserID = $this->UserID();
		if (!($AccessToken && $VkUserID)) {
            // Exchange the token for an access token.
            $Code = urlencode($Code);
            $Url = "https://api.vkontakte.ru/oauth/access_token?client_id=$AppID&client_secret=$Secret&code=$Code&redirect_uri=$RedirectUri";
            // Get the redirect URI.
            $C = curl_init();
            curl_setopt($C, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($C, CURLOPT_SSL_VERIFYPEER, FALSE);
            curl_setopt($C, CURLOPT_URL, $Url);
            $Contents = curl_exec($C);
            $Info = curl_getinfo($C);
			
			$ContentType = GetValue('content_type', $Info, '');
			switch ($ContentType) {
				case 'application/x-javascript':
				case 'application/javascript':
				case 'application/json': {
					$Tokens = json_decode($Contents, True); 
					break;
				}
				default: parse_str($Contents, $Tokens);
			}
			
            if (GetValue('error', $Tokens)) {
				$ErrorMesssage = sprintf(T('Vkontakte returned the following error: %s'), GetValue('error_description', $Tokens, 'Unknown error.'));
                throw new Gdn_UserException($ErrorMesssage, 400);
            }
			
            $AccessToken = GetValue('access_token', $Tokens);
			$VkUserID = GetValue('user_id', $Tokens, NULL);
            $Expires = GetValue('expires_in', $Tokens, NULL);

            setcookie('vk_access_token', $AccessToken, time() + $Expires, C('Garden.Cookie.Path', '/'), C('Garden.Cookie.Domain', ''));
            setcookie('vk_user_id', $VkUserID, time() + $Expires, C('Garden.Cookie.Path', '/'), C('Garden.Cookie.Domain', ''));
            $NewToken = TRUE;
		}
		
		try {
            $Profile = $this->GetProfile($AccessToken, $VkUserID);
		} catch (Exception $Ex) {
            if (!isset($NewToken)) {
                // There was an error getting the profile, which probably means the saved access token is no longer valid. Try and reauthorize.
                if ($Sender->DeliveryType() == DELIVERY_TYPE_ALL) {
                    Redirect($this->AuthorizeUri());
                } else {
                    $Sender->SetHeader('Content-type', 'application/json');
                    $Sender->DeliveryMethod(DELIVERY_METHOD_JSON);
                    $Sender->RedirectUrl = $this->AuthorizeUri();
                }
            } else {
				if ($Ex->GetMessage() == '') $Ex = 'There was an error with the Vkontakte connection.';
                $Sender->Form->AddError($Ex);
            }
		}
		$Form = $Sender->Form;
		$Form->SetFormValue('UniqueID', $Profile['UniqueID']);
		$Form->SetFormValue('Provider', 'vkauth');
		$Form->SetFormValue('ProviderName', 'Vkontakte');
		$Form->SetFormValue('FullName', GetValue('FullName', $Profile));
		$Name = GetValue('nickname', $Profile);
		if (!$Name) $Name = GetValue('FirstName', $Profile);
		$Form->SetFormValue('Name', $Name);
		// Vk API doesnt give us email.
		if ($Email = GetValue('email', $Profile)) $Form->SetFormValue('Email', $Email);
		if ($Photo = GetValue('Photo', $Profile)) $Form->SetFormValue('Photo', $Photo);
		$Sender->SetData('Verified', True);
	}

	/**
	* Documentation is here: http://vkontakte.ru/developers.php?oid=-1&p=getProfiles
	* 
	*/
	public function GetProfile($AccessToken, $VkUserID) {
		$Parameters = 'uids='.$VkUserID.'&fields=uid,first_name,last_name,nickname,screen_name,sex,bdate,city,country,timezone,photo,photo_medium,photo_big';
		$MethodName = 'getProfiles';
		$Url = "https://api.vkontakte.ru/method/{$MethodName}?{$Parameters}&access_token=$AccessToken";
		$Contents = file_get_contents($Url);
		$Result = json_decode($Contents, True);
		if (isset($Result['error']) || $Result == False) {
			$ErrorCode = GetValueR('error.error_code', $Result, 500);
			$ErrorMesssage = GetValueR('error.error_msg', $Result, 'Unknown error');
			throw new Gdn_UserException($ErrorMesssage, $ErrorCode);
		}
		// Build profile.
		$data = $Result['response'][0];
		$Profile = $data;
		$Profile['UniqueID'] = $data['uid'];
		$Profile['FirstName'] = $data['first_name'];
		$Profile['Gender'] = ($data['sex'] == 1) ? 'f' : 'm';
		$Profile['FamilyName'] = $data['last_name'];
		$Profile['FullName'] = trim($data['first_name'] . ' ' . $data['last_name']);
		$Profile['Photo'] = $data['photo_big'];
		if (array_key_exists('bdate', $data)) $Profile['DateOfBirth'] = Gdn_Format::ToDateTime(strtotime($data['bdate']));
		
		// Get city
		$Parameters = 'cids='.GetValue('city', $data);
		$Url = "https://api.vkontakte.ru/method/places.getCityById?{$Parameters}&access_token=$AccessToken";
		$Contents = file_get_contents($Url);
		$Result = json_decode($Contents, True);
		if (isset($Result['error']) || $Result == False) {
			$ErrorCode = GetValueR('error.error_code', $Result, 500);
			$ErrorMesssage = GetValueR('error.error_msg', $Result, 'Unknown error');
			throw new Gdn_UserException($ErrorMesssage, $ErrorCode);
		}
		$Profile['City'] = GetValueR('response.0.name', $Result);
		return $Profile;
	}

	protected $_RedirectUri;

	public function RedirectUri($NewValue = NULL) {
		if ($NewValue !== Null) $this->_RedirectUri = $NewValue;
		elseif ($this->_RedirectUri === Null) {
            $RedirectUri = Url('/entry/connect/vkauth', True);
            if (strpos($RedirectUri, '=') !== False) {
                $p = strrchr($RedirectUri, '=');
                $Uri = substr($RedirectUri, 0, -strlen($p));
                $p = urlencode(ltrim($p, '='));
                $RedirectUri = $Uri.'='.$p;
            }

            $Path = Gdn::Request()->Path();

            $Target = GetValue('Target', $_GET, $Path ? $Path : '/');
            if (ltrim($Target, '/') == 'entry/signin') $Target = '/';
            $Args = array('Target' => $Target);

            $RedirectUri .= strpos($RedirectUri, '?') === False ? '?' : '&';
            $RedirectUri .= http_build_query($Args);
            $this->_RedirectUri = $RedirectUri;
		}
		
		return $this->_RedirectUri;
	}

	public function IsConfigured() {
		$AppID = C('Plugins.VkAuth.ApplicationID');
		$Secret = C('Plugins.VkAuth.Secret');
		if (!$AppID || !$Secret) return FALSE;
		return True;
	}
	
	public function SettingsController_VkAuth_Create($Sender, $Args) {
		$Sender->Permission('Garden.Settings.Manage');
		if ($Sender->Form->IsPostBack()) {
            $Settings = array(
                'Plugins.VkAuth.ApplicationID' => $Sender->Form->GetFormValue('ApplicationID'),
                'Plugins.VkAuth.Secret' => $Sender->Form->GetFormValue('Secret')
            );
            SaveToConfig($Settings);
            $Sender->InformMessage(T("Your settings have been saved."), array('Sprite' => 'Check', 'CssClass' => 'Dismissable'));

		} else {
            $Sender->Form->SetFormValue('ApplicationID', C('Plugins.VkAuth.ApplicationID'));
            $Sender->Form->SetFormValue('Secret', C('Plugins.VkAuth.Secret'));
		}

		$Sender->AddSideMenu();
		$Sender->Title(T('VkAuth Settings'));
		$Sender->Render('Settings', '', 'plugins/VkAuth');
	}
	
	public function Setup() {
		$Error = '';
		if (!ini_get('allow_url_fopen')) $Error = ConcatSep("\n", $Error, 'This plugin requires the allow_url_fopen php.ini setting.');
		if (!function_exists('curl_init')) $Error = ConcatSep("\n", $Error, 'This plugin requires curl.');
		if ($Error) throw new Gdn_UserException($Error, 400);
		$this->Structure();
	}

	public function Structure() {
	}

	public function OnDisable() {
	}

}