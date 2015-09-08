<?php
    
class iMoneza_ResourceAccess extends iMoneza_API {

    protected $cookieExpiration;

    public function __construct()
    {
        $options = get_option('imoneza_options');
        parent::__construct($options, $options['ra_api_key_access'], $options['ra_api_key_secret'], IMONEZA__RA_API_URL);

        // 14 days
        $this->cookieExpiration = 60 * 60 * 24 * 14;
    }

    public function getResourceAccess($externalKey, $resourceURL)
    {
        try {
            $userToken = '';

            // Check for excluded user agents
            if (isset($this->options['access_control_excluded_user_agents']) && $this->options['access_control_excluded_user_agents'] != '') {
                foreach (explode("\n", $this->options['access_control_excluded_user_agents']) as $userAgent) {
                    if ($userAgent == $_SERVER['HTTP_USER_AGENT'])
                        return;
                }
            }

            if (isset($_REQUEST['iMonezaTUT'])) {
                // The user just authenticated at iMoneza, and iMoneza is sending the temporary user token back to us
                $temporaryUserToken = $_REQUEST['iMonezaTUT'];
                $resourceAccessData = $this->getResourceAccessDataFromTemporaryUserToken($externalKey, $resourceURL, $temporaryUserToken);
            } else {
                $userToken = $_COOKIE['iMonezaUT'];
                $resourceAccessData = $this->getResourceAccessDataFromExternalKey($externalKey, $resourceURL, $userToken);
            }

            $userToken = $resourceAccessData['UserToken'];
            setcookie('iMonezaUT', $userToken, time() + $this->cookieExpiration, '/');

            // Prevent major caching plugins (WP Super Cache, W3 Total Cache) from caching the page if it's an iMoneza-managed resource
            if ($resourceAccessData['AccessReason'] == 'Deny' || $resourceAccessData['AccessReason'] == 'Quota' || $resourceAccessData['AccessReason'] == 'Subscription' || $resourceAccessData['AccessReason'] == 'Purchase' || $resourceAccessData['AccessReason'] == 'Free' || $resourceAccessData['AccessReason'] == 'PropertyUser') {
                define('DONOTCACHEPAGE', TRUE);
            }

            if ($resourceAccessData['AccessActionURL'] && strlen($resourceAccessData['AccessActionURL']) > 0)
            {
                $url = $resourceAccessData['AccessActionURL'];
                $url = $url . '&OriginalURL=' . rawurlencode($resourceURL);
                wp_redirect($url);
                exit;
            }
        } catch (Exception $e) {
            // Default to open access if there's some sort of exception
            if (IMONEZA__DEBUG)
                throw $e;
        }
    }

    public function getResourceAccessDataFromExternalKey($externalKey, $resourceURL, $userToken) {
        $request = new iMoneza_RestfulRequest($this);
        $request->method = 'GET';
        $request->uri = '/api/Resource/' . $this->accessKey . '/' . $externalKey;
        $request->getParameters['ResourceURL'] = $resourceURL;
        $request->getParameters['UserToken'] = $userToken;
        $request->getParameters['IP'] = $this->getClientIP();

        $response = $request->getResponse();

        if ($response['response']['code'] == '404') {
            throw new Exception('An error occurred with the Resource Access API key. Make sure you have valid Access Management API keys set in the iMoneza plugin settings.');
        } else {
            return json_decode($response['body'], true);
        }
    }

    public function getResourceAccessDataFromTemporaryUserToken($externalKey, $resourceURL, $temporaryUserToken) {
        $request = new iMoneza_RestfulRequest($this);
        $request->method = 'GET';
        $request->uri = '/api/TemporaryUserToken/' . $this->accessKey . '/' . $temporaryUserToken;
        $request->getParameters['ResourceKey'] = $externalKey;
        $request->getParameters['ResourceURL'] = $resourceURL;
        $request->getParameters['IP'] = $this->getClientIP();

        $response = $request->getResponse();

        if ($response['response']['code'] == '404') {
            throw new Exception('An error occurred with the Resource Access API key. Make sure you have valid Access Management API keys set in the iMoneza plugin settings.');
        } else {
            return json_decode($response['body'], true);
        }
    }

    private function getClientIP() {
        $ipAddress = '';
        if (getenv('HTTP_CLIENT_IP'))
            $ipAddress = getenv('HTTP_CLIENT_IP');
        else if (getenv('HTTP_X_FORWARDED_FOR'))
            $ipAddress = getenv('HTTP_X_FORWARDED_FOR');
        else if (getenv('HTTP_X_FORWARDED'))
            $ipAddress = getenv('HTTP_X_FORWARDED');
        else if (getenv('HTTP_FORWARDED_FOR'))
            $ipAddress = getenv('HTTP_FORWARDED_FOR');
        else if (getenv('HTTP_FORWARDED'))
           $ipAddress = getenv('HTTP_FORWARDED');
        else if (getenv('REMOTE_ADDR'))
            $ipAddress = getenv('REMOTE_ADDR');

        // Strip out the port number on an IPv4 address
        if (substr_count($ipAddress, '.') == 3 && strpos($ipAddress, ':') > 0) {
            $parts = explode(':', $ipAddress, 2);
            $ipAddress = $parts[0];
        }
            
        return $ipAddress;
    }
}
?>