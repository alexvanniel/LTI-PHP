<?php

namespace ceLTIc\LTI;

use ceLTIc\LTI\DataConnector\DataConnector;
use ceLTIc\LTI\Content\Item;
use ceLTIc\LTI\OAuth;
use ceLTIc\LTI\Jwt\Jwt;
use ceLTIc\LTI\Jwt\ClientInterface;

/**
 * Class to represent an LTI system
 *
 * @author  Stephen P Vickers <stephen@spvsoftwareproducts.com>
 * @copyright  SPV Software Products
 * @license  http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3
 */
trait System
{

    /**
     * True if the last request was successful.
     *
     * @var bool $ok
     */
    public $ok = true;

    /**
     * Shared secret.
     *
     * @var string|null $secret
     */
    public $secret = null;

    /**
     * Method used for signing messages.
     *
     * @var string $signatureMethod
     */
    public $signatureMethod = 'HMAC-SHA1';

    /**
     * Algorithm used for encrypting messages.
     *
     * @var string $encryptionMethod
     */
    public $encryptionMethod = '';

    /**
     * Data connector object.
     *
     * @var DataConnector|null $dataConnector
     */
    public $dataConnector = null;

    /**
     * RSA key in PEM or JSON format.
     *
     * Set to the private key for signing outgoing messages and service requests, and to the public key
     * for verifying incoming messages and service requests.
     *
     * @var string|null $rsaKey
     */
    public $rsaKey = null;

    /**
     * Scopes to request when obtaining an access token.
     *
     * @var array  $requiredScopes
     */
    public $requiredScopes = array();

    /**
     * Key ID.
     *
     * @var string|null $kid
     */
    public $kid = null;

    /**
     * Endpoint for public key.
     *
     * @var string|null $jku
     */
    public $jku = null;

    /**
     * Error message for last request processed.
     *
     * @var string|null $reason
     */
    public $reason = null;

    /**
     * Details for error message relating to last request processed.
     *
     * @var array $details
     */
    public $details = array();

    /**
     * Whether debug level messages are to be reported.
     *
     * @var bool $debugMode
     */
    public $debugMode = false;

    /**
     * JWT object, if any.
     *
     * @var JWS|null $jwt
     */
    protected $jwt = null;

    /**
     * Raw message parameters.
     *
     * @var array $rawParameters
     */
    protected $rawParameters = null;

    /**
     * LTI message parameters.
     *
     * @var array|null $messageParameters
     */
    protected $messageParameters = null;

    /**
     * Consumer key/client ID value.
     *
     * @var string|null $key
     */
    private $key = null;

    /**
     * Get the consumer key.
     *
     * @return string  Consumer key value
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Set the consumer key.
     *
     * @param string $key  Consumer key value
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * Check whether a JWT exists
     *
     * @return bool True if a JWT exists
     */
    public function hasJwt()
    {
        return !empty($this->jwt) && $this->jwt->hasJwt();
    }

    /**
     * Get the JWT
     *
     * @return ClientInterface The JWT
     */
    public function getJwt()
    {
        return $this->jwt;
    }

    /**
     * Get the raw POST parameters
     *
     * @return array The POST parameter array
     */
    public function getRawParameters()
    {
        if (is_null($this->rawParameters)) {
            $this->rawParameters = OAuth\OAuthUtil::parse_parameters(file_get_contents(OAuth\OAuthRequest::$POST_INPUT));
        }

        return $this->rawParameters;
    }

    /**
     * Get the message claims
     *
     * @param bool $fullyQualified  True if claims should be fully qualified rather than grouped (default is false)
     *
     * @return array The message claim array
     */
    public function getMessageClaims($fullyQualified = false)
    {
        $messageClaims = null;
        if (!is_null($this->messageParameters)) {
            $messageParameters = $this->messageParameters;
            if (!empty($messageParameters['lti_message_type']) && array_key_exists($messageParameters['lti_message_type'],
                    Util::MESSAGE_TYPE_MAPPING)) {
                $messageParameters['lti_message_type'] = Util::MESSAGE_TYPE_MAPPING[$messageParameters['lti_message_type']];
            }
            if (!empty($messageParameters['accept_media_types'])) {
                $mediaTypes = explode(',', $messageParameters['accept_media_types']);
                $types = array();
                if (!empty($messageParameters['accept_types'])) {
                    $types = explode(',', $messageParameters['accept_types']);
                }
                foreach ($mediaTypes as $mediaType) {
                    if ($mediaType === Item::LTI_LINK_MEDIA_TYPE) {
                        unset($mediaTypes[array_search(Item::LTI_LINK_MEDIA_TYPE, $mediaTypes)]);
                        $types[] = Item::TYPE_LTI_LINK;
                    } elseif (substr($mediaType, 0, 6) === 'image/') {
                        $types[] = 'image';
                    } elseif (substr($mediaType, 0, 6) === 'text/html') {
                        $types[] = 'html';
                    } elseif (substr($mediaType, 0, 6) === '*/*') {
                        $types[] = 'html';
                        $types[] = 'file';
                        $types[] = 'link';
                    } else {
                        $types[] = 'file';
                    }
                }
                $messageParameters['accept_media_types'] = implode(',', $mediaTypes);
                $types = array_unique($types);
                $messageParameters['accept_types'] = implode(',', $types);
            }
            $messageClaims = array();
            foreach ($messageParameters as $key => $value) {
                $ok = true;
                if (array_key_exists($key, Util::JWT_CLAIM_MAPPING)) {
                    $mapping = Util::JWT_CLAIM_MAPPING[$key];
                    if (isset($mapping['isObject']) && $mapping['isObject']) {
                        $value = json_decode($value);
                    } elseif (isset($mapping['isArray']) && $mapping['isArray']) {
                        $value = explode(',', $value);
                        sort($value);
                    } elseif (isset($mapping['isBoolean']) && $mapping['isBoolean']) {
                        $value = $value === 'true';
                    }
                    $group = '';
                    $claim = Util::JWT_CLAIM_PREFIX;
                    if (!empty($mapping['suffix'])) {
                        $claim .= "-{$mapping['suffix']}";
                    }
                    $claim .= '/claim/';
                    if (is_null($mapping['group'])) {
                        $claim = $mapping['claim'];
                    } elseif (empty($mapping['group'])) {
                        $claim .= $mapping['claim'];
                    } else {
                        $group = $claim . $mapping['group'];
                        $claim = $mapping['claim'];
                    }
                } elseif (substr($key, 0, 7) === 'custom_') {
                    $group = Util::JWT_CLAIM_PREFIX . '/claim/custom';
                    $claim = substr($key, 7);
                } elseif (substr($key, 0, 4) === 'ext_') {
                    $group = Util::JWT_CLAIM_PREFIX . '/claim/ext';
                    $claim = substr($key, 4);
                } else {
                    $ok = false;
                }
                if ($ok) {
                    if ($fullyQualified) {
                        if (empty($group)) {
                            $messageClaims[$claim] = $value;
                        } else {
                            $messageClaims["{$group}/{$claim}"] = $value;
                        }
                    } elseif (empty($group)) {
                        $messageClaims[$claim] = $value;
                    } else {
                        $messageClaims[$group][$claim] = $value;
                    }
                }
            }
        }

        return $messageClaims;
    }

    /**
     * Get an array of fully qualified user roles
     *
     * @param mixed  $roles       Comma-separated list of roles or array of roles
     * @param string $ltiVersion  LTI version (default is LTI-1p0)
     *
     * @return array Array of roles
     */
    public static function parseRoles($roles, $ltiVersion = Util::LTI_VERSION1)
    {
        if (!is_array($roles)) {
            $roles = explode(',', $roles);
        }
        $parsedRoles = array();
        foreach ($roles as $role) {
            $role = trim($role);
            if (!empty($role)) {
                if ($ltiVersion === Util::LTI_VERSION1) {
                    if ((substr($role, 0, 4) !== 'urn:') &&
                        (substr($role, 0, 7) !== 'http://') && (substr($role, 0, 8) !== 'https://')) {
                        $role = 'urn:lti:role:ims/lis/' . $role;
                    }
                } elseif ((substr($role, 0, 7) !== 'http://') && (substr($role, 0, 8) !== 'https://')) {
                    $role = 'http://purl.imsglobal.org/vocab/lis/v2/membership#' . $role;
                }
                $parsedRoles[] = $role;
            }
        }

        return $parsedRoles;
    }

    /**
     * Add the signature to an LTI message.
     *
     * @param string  $url         URL for message request
     * @param string  $type        LTI message type
     * @param string  $version     LTI version
     * @param array   $params      Message parameters
     *
     * @return array|string  Array of signed message parameters or request headers
     */
    public function signParameters($url, $type, $version, $params)
    {
        if (!empty($url)) {
// Add standard parameters
            $params['lti_version'] = $version;
            $params['lti_message_type'] = $type;
// Add signature
            $params = $this->addSignature($url, $params, 'POST', 'application/x-www-form-urlencoded');
        }

        return $params;
    }

    /**
     * Generates the headers for an LTI service request.
     *
     * @param string  $url         URL for message request
     * @param string  $method      HTTP method
     * @param string  $type        Media type
     * @param string  $data        Data being passed in request body (optional)
     *
     * @return string Headers to include with service request
     */
    public function signServiceRequest($url, $method, $type, $data = null)
    {
        $header = '';
        if (!empty($url)) {
            $header = $this->addSignature($url, $data, $method, $type);
        }

        return $header;
    }

    /**
     * Perform a service request
     *
     * @param object $service  Service object to be executed
     * @param string $method   HTTP action
     * @param string $format   Media type
     * @param mixed  $data     Array of parameters or body string
     *
     * @return HttpMessage HTTP object containing request and response details
     */
    public function doServiceRequest($service, $method, $format, $data)
    {
        $header = $this->addSignature($service->endpoint, $data, $method, $format);

// Connect to platform
        $http = new HttpMessage($service->endpoint, $method, $data, $header);
// Parse JSON response
        if ($http->send() && !empty($http->response)) {
            $http->responseJson = json_decode($http->response);
            $http->ok = !is_null($http->responseJson);
        }

        return $http;
    }

    /**
     * Determine whether this consumer is using the OAuth 1 security model.
     *
     * @return bool  True if OAuth 1 security model should be used
     */
    public function useOAuth1()
    {
        return empty($this->signatureMethod) || (substr($this->signatureMethod, 0, 2) !== 'RS');
    }

    /**
     * Add the signature to an array of message parameters or to a header string.
     *
     * @param string $endpoint          URL to which message is being sent
     * @param mixed $data               Data to be passed
     * @param string $method            HTTP method
     * @param string|null $type         Content type of data being passed
     * @param string|null $nonce        Nonce value for JWT
     * @param string|null $hash         OAuth body hash value
     * @param int|null $timestamp       Timestamp
     *
     * @return mixed Array of signed message parameters or header string
     */
    public function addSignature($endpoint, $data, $method = 'POST', $type = null, $nonce = '', $hash = null, $timestamp = null)
    {
        if ($this->useOAuth1()) {
            return $this->addOAuth1Signature($endpoint, $data, $method, $type, $hash, $timestamp);
        } else {
            return $this->addJWTSignature($endpoint, $data, $method, $type, $nonce, $timestamp);
        }
    }

    /**
     * Verify the signature of a message.
     *
     * @return bool  True if the signature is valid
     */
    public function verifySignature()
    {
        $ok = false;
        if ($this instanceof Tool) {
            $platform = $this->platform;
        } else {
            $platform = $this;
        }
        if (isset($this->messageParameters['oauth_signature_method'])) {
            $this->signatureMethod = $this->messageParameters['oauth_signature_method'];
            if ($this instanceof Tool) {
                $this->platform->signatureMethod = $this->messageParameters['oauth_signature_method'];
            }
        }
        if (empty($this->jwt) || empty($this->jwt->hasJwt())) {  // OAuth-signed message
            try {
                $store = new OAuthDataStore($this);
                $server = new OAuth\OAuthServer($store);
                $method = new OAuth\OAuthSignatureMethod_HMAC_SHA224();
                $server->add_signature_method($method);
                $method = new OAuth\OAuthSignatureMethod_HMAC_SHA256();
                $server->add_signature_method($method);
                $method = new OAuth\OAuthSignatureMethod_HMAC_SHA384();
                $server->add_signature_method($method);
                $method = new OAuth\OAuthSignatureMethod_HMAC_SHA512();
                $server->add_signature_method($method);
                $method = new OAuth\OAuthSignatureMethod_HMAC_SHA1();
                $server->add_signature_method($method);
                $request = OAuth\OAuthRequest::from_request();
                $server->verify_request($request);
                $ok = true;
            } catch (\Exception $e) {
                if (empty($this->reason)) {
                    $oauthConsumer = new OAuth\OAuthConsumer($platform->getKey(), $platform->secret);
                    $signature = $request->build_signature($method, $oauthConsumer, false);
                    if ($this->debugMode) {
                        $this->reason = $e->getMessage();
                    }
                    if (empty($this->reason)) {
                        $this->reason = 'OAuth signature check failed - perhaps an incorrect secret or timestamp.';
                    }
                    $this->details[] = 'Current timestamp: ' . time();
                    $this->details[] = "Expected signature: {$signature}";
                    $this->details[] = "Base string: {$request->base_string}";
                }
            }
        } else {  // JWT-signed message
            $nonce = new PlatformNonce($platform, $this->jwt->getClaim('nonce'));
            $ok = !$nonce->load();
            if ($ok) {
                $ok = $nonce->save();
            }
            if (!$ok) {
                $this->reason = 'Invalid nonce.';
            } elseif (!empty($platform->rsaKey) || !empty($platform->jku) || Jwt::$allowJkuHeader) {
                unset($this->messageParameters['oauth_consumer_key']);
                $ok = $this->jwt->verify($platform->rsaKey, $platform->jku);
                if (!$ok) {
                    $this->reason = 'JWT signature check failed - perhaps an invalid public key or timestamp';
                }
            } else {
                $ok = false;
                $this->reason = 'Unbale to verify JWT signature as neither a public key nor a JSON Web Key URL is specified';
            }
        }

        return $ok;
    }

###
###    PRIVATE METHODS
###

    /**
     * Parse the message
     */
    private function parseMessage()
    {
        if (is_null($this->messageParameters)) {
            $this->getRawParameters();
            if (isset($this->rawParameters['id_token']) || isset($this->rawParameters['JWT'])) {  // JWT-signed message
                try {
                    $this->jwt = Jwt::getJwtClient();
                    if (isset($this->rawParameters['id_token'])) {
                        $this->jwt->load($this->rawParameters['id_token']);
                    } else {
                        $this->jwt->load($this->rawParameters['JWT']);
                    }
                    $this->ok = $this->jwt->hasClaim('iss') && $this->jwt->hasClaim('aud') &&
                        $this->jwt->hasClaim(Util::JWT_CLAIM_PREFIX . '/claim/deployment_id');
                    if ($this->ok) {
                        $iss = $this->jwt->getClaim('iss');
                        $aud = $this->jwt->getClaim('aud');
                        $deploymentId = $this->jwt->getClaim(Util::JWT_CLAIM_PREFIX . '/claim/deployment_id');
                        $this->ok = !empty($iss) && !empty($aud) && !empty($deploymentId);
                        if (!$this->ok) {
                            $this->reason = 'iss, aud and/or deployment_id claim is empty';
                        } elseif (is_array($aud)) {
                            if ($this->jwt->hasClaim('azp')) {
                                $this->ok = !empty($this->jwt->getClaim('azp'));
                                if (!$this->ok) {
                                    $this->reason = 'azp claim is empty';
                                } else {
                                    $this->ok = in_array($this->jwt->getClaim('azp'), $aud);
                                    if ($this->ok) {
                                        $aud = $this->jwt->getClaim('azp');
                                    } else {
                                        $this->reason = 'azp claim value is not included in aud claim';
                                    }
                                }
                            } else {
                                $aud = $aud[0];
                                $this->ok = !empty($aud);
                                if (!$this->ok) {
                                    $this->reason = 'First element of aud claim is empty';
                                }
                            }
                        } elseif ($this->jwt->hasClaim('azp')) {
                            $this->ok = $this->jwt->getClaim('azp') === $aud;
                            if (!$this->ok) {
                                $this->reason = 'aud claim does not match the azp claim';
                            }
                        }
                        if ($this->ok) {
                            $this->platform = Platform::fromPlatformId($iss, $aud, $deploymentId, $this->dataConnector);
                            if (isset($this->rawParameters['id_token'])) {
                                $this->ok = !empty($this->rawParameters['state']);
                                if ($this->ok) {
                                    $nonce = new PlatformNonce($this->platform, $this->rawParameters['state']);
                                    $this->ok = $nonce->load();
                                    if ($this->ok) {
                                        $this->ok = $nonce->delete();
                                    }
                                }
                            }
                            if ($this->ok) {
                                $this->platform->platformId = $this->jwt->getClaim('iss');
                                $this->messageParameters = array();
                                $this->messageParameters['oauth_consumer_key'] = $aud;
                                $this->messageParameters['oauth_signature_method'] = $this->jwt->getHeader('alg');
                                $this->parseClaims();
                            } else {
                                $this->reason = 'state parameter is invalid or missing';
                            }
                        }
                    } else {
                        $this->reason = 'iss, aud and/or deployment_id claim not found';
                    }
                } catch (\Exception $e) {
                    $this->ok = false;
                    $this->reason = 'Message does not contain a valid JWT';
                }
            } elseif (isset($this->rawParameters['error'])) {  // Error with JWT-signed message
                $this->ok = false;
                $this->reason = $this->rawParameters['error'];
            } else {  // OAuth
                if (isset($this->rawParameters['oauth_consumer_key'])) {
                    $this->platform = Platform::fromConsumerKey($this->rawParameters['oauth_consumer_key'], $this->dataConnector);
                }
                $this->messageParameters = $this->rawParameters;
            }
        }
    }

    /**
     * Parse the claims
     */
    private function parseClaims()
    {
        foreach (Util::JWT_CLAIM_MAPPING as $key => $mapping) {
            $claim = Util::JWT_CLAIM_PREFIX;
            if (!empty($mapping['suffix'])) {
                $claim .= "-{$mapping['suffix']}";
            }
            $claim .= '/claim/';
            if (is_null($mapping['group'])) {
                $claim = $mapping['claim'];
            } elseif (empty($mapping['group'])) {
                $claim .= $mapping['claim'];
            } else {
                $claim .= $mapping['group'];
            }
            if ($this->jwt->hasClaim($claim)) {
                $value = null;
                if (empty($mapping['group'])) {
                    $value = $this->jwt->getClaim($claim);
                } else {
                    $group = $this->jwt->getClaim($claim);
                    if (is_array($group) && array_key_exists($mapping['claim'], $group)) {
                        $value = $group[$mapping['claim']];
                    } elseif (is_object($group) && isset($group->{$mapping['claim']})) {
                        $value = $group->{$mapping['claim']};
                    }
                }
                if (!is_null($value)) {
                    if (isset($mapping['isArray']) && $mapping['isArray']) {
                        if (!is_array($value)) {
                            $this->ok = false;
                        } else {
                            $value = implode(',', $value);
                        }
                    } elseif (isset($mapping['isObject']) && $mapping['isObject']) {
                        $value = json_encode($value);
                    } elseif (isset($mapping['isBoolean']) && $mapping['isBoolean']) {
                        $value = $value ? 'true' : 'false';
                    }
                }
                if (!is_null($value) && is_string($value)) {
                    $this->messageParameters[$key] = $value;
                }
            }
        }
        if (!empty($this->messageParameters['lti_message_type']) &&
            in_array($this->messageParameters['lti_message_type'], array_values(Util::MESSAGE_TYPE_MAPPING))) {
            $this->messageParameters['lti_message_type'] = array_search($this->messageParameters['lti_message_type'],
                Util::MESSAGE_TYPE_MAPPING);
        }
        if (!empty($this->messageParameters['accept_types'])) {
            $types = explode(',', $this->messageParameters['accept_types']);
            $mediaTypes = array();
            if (!empty($this->messageParameters['accept_media_types'])) {
                $mediaTypes = explode(',', $this->messageParameters['accept_media_types']);
            }
            if (in_array(Item::TYPE_LTI_LINK, $types)) {
                $mediaTypes[] = Item::LTI_LINK_MEDIA_TYPE;
            }
            if (in_array('html', $types) && !in_array('*/*', $mediaTypes)) {
                $mediaTypes[] = 'text/html';
            }
            if (in_array('image', $types) && !in_array('*/*', $mediaTypes)) {
                $mediaTypes[] = 'image/*';
            }
            $mediaTypes = array_unique($mediaTypes);
            $this->messageParameters['accept_media_types'] = implode(',', $mediaTypes);
        }
        $claim = Util::JWT_CLAIM_PREFIX . '/claim/custom';
        if ($this->jwt->hasClaim($claim)) {
            $custom = $this->jwt->getClaim($claim);
            if (!is_array($custom) && !is_object($custom)) {
                $this->ok = false;
            } else {
                foreach ($custom as $key => $value) {
                    $this->messageParameters["custom_{$key}"] = $value;
                }
            }
        }
        $claim = Util::JWT_CLAIM_PREFIX . '/claim/ext';
        if ($this->jwt->hasClaim($claim)) {
            $ext = $this->jwt->getClaim($claim);
            if (!is_array($ext) && !is_object($ext)) {
                $this->ok = false;
            } else {
                foreach ($ext as $key => $value) {
                    $this->messageParameters["ext_{$key}"] = $value;
                }
            }
        }
    }

    /**
     * Add the OAuth 1 signature to an array of message parameters or to a header string.
     *
     * @param string $endpoint          URL to which message is being sent
     * @param mixed $data               Data to be passed
     * @param string $method            HTTP method
     * @param string|null $type         Content type of data being passed
     * @param string|null $hash         OAuth body hash value
     * @param int|null $timestamp       Timestamp
     *
     * @return string[]|string Array of signed message parameters or header string
     */
    private function addOAuth1Signature($endpoint, $data, $method, $type, $hash, $timestamp)
    {
        $params = array();
        if (is_array($data)) {
            $params = $data;
            $params['oauth_callback'] = 'about:blank';
        }
// Check for query parameters which need to be included in the signature
        $queryString = parse_url($endpoint, PHP_URL_QUERY);
        $queryParams = OAuth\OAuthUtil::parse_parameters($queryString);
        $params = array_merge_recursive($queryParams, $params);

        if (!is_array($data)) {
            if (empty($hash)) {  // Calculate body hash
                switch ($this->signatureMethod) {
                    case 'HMAC-SHA224':
                        $hash = base64_encode(hash('sha224', $data, true));
                        break;
                    case 'HMAC-SHA256':
                        $hash = base64_encode(hash('sha256', $data, true));
                        break;
                    case 'HMAC-SHA384':
                        $hash = base64_encode(hash('sha384', $data, true));
                        break;
                    case 'HMAC-SHA512':
                        $hash = base64_encode(hash('sha512', $data, true));
                        break;
                    default:
                        $hash = base64_encode(sha1($data, true));
                        break;
                }
            }
            $params['oauth_body_hash'] = $hash;
        }
        if (!empty($timestamp)) {
            $params['oauth_timestamp'] = $timestamp;
        }

// Add OAuth signature
        switch ($this->signatureMethod) {
            case 'HMAC-SHA224':
                $hmacMethod = new OAuth\OAuthSignatureMethod_HMAC_SHA224();
                break;
            case 'HMAC-SHA256':
                $hmacMethod = new OAuth\OAuthSignatureMethod_HMAC_SHA256();
                break;
            case 'HMAC-SHA384':
                $hmacMethod = new OAuth\OAuthSignatureMethod_HMAC_SHA384();
                break;
            case 'HMAC-SHA512':
                $hmacMethod = new OAuth\OAuthSignatureMethod_HMAC_SHA512();
                break;
            default:
                $hmacMethod = new OAuth\OAuthSignatureMethod_HMAC_SHA1();
                break;
        }
        $oauthConsumer = new OAuth\OAuthConsumer($this->key, $this->secret, null);
        $oauthReq = OAuth\OAuthRequest::from_consumer_and_token($oauthConsumer, null, $method, $endpoint, $params);
        $oauthReq->sign_request($hmacMethod, $oauthConsumer, null);
        if (!is_array($data)) {
            $header = $oauthReq->to_header();
            if (empty($data)) {
                if (!empty($type)) {
                    $header .= "\nAccept: {$type}";
                }
            } elseif (isset($type)) {
                $header .= "\nContent-Type: {$type}";
                $header .= "\nContent-Length: " . strlen($data);
            }
            return $header;
        } else {
            $params = $oauthReq->get_parameters();
            foreach ($queryParams as $key => $value) {
                if (!is_array($value)) {
                    if (!is_array($params[$key])) {
                        if ($params[$key] === $value) {
                            unset($params[$key]);
                        }
                    } else {
                        $params[$key] = array_diff($params[$key], array($value));
                    }
                } else {
                    foreach ($value as $element) {
                        $params[$key] = array_diff($params[$key], array($value));
                    }
                }
            }
            return $params;
        }
    }

    /**
     * Add the JWT signature to an array of message parameters or to a header string.
     *
     * @param string $endpoint          URL to which message is being sent
     * @param mixed $data               Data to be passed
     * @param string $method            HTTP method
     * @param string|null $type         Content type of data being passed
     * @param string|null $nonce        Nonce value for JWT
     * @param int|null $timestamp       Timestamp
     *
     * @return string[]|string Array of signed message parameters or header string
     */
    private function addJWTSignature($endpoint, $data, $method, $type, $nonce, $timestamp)
    {
        $ok = false;
        if (is_array($data)) {
            if (empty($nonce)) {
                $nonce = Util::getRandomString(32);
            }
            if (!array_key_exists('grant_type', $data)) {
                $this->messageParameters = $data;
                $payload = $this->getMessageClaims();
                $ok = count($payload) > 2;
                if ($ok) {
                    if ($this instanceof Tool) {
                        $privateKey = $this->rsaKey;
                        $kid = $this->kid;
                        $jku = $this->jku;
                        if (!empty($this->baseUrl)) {
                            $payload['iss'] = $this->baseUrl;
                        } else {
                            $payload['iss'] = $this->platform->platformId;
                        }
                        $payload['aud'] = array($this->platform->platformId);
                        $payload['azp'] = $this->platform->platformId;
                        $payload[Util::JWT_CLAIM_PREFIX . '/claim/deployment_id'] = $this->platform->deploymentId;
                        $paramName = 'JWT';
                    } else {
                        if (!empty(Tool::$defaultTool)) {
                            $privateKey = Tool::$defaultTool->rsaKey;
                            $kid = Tool::$defaultTool->kid;
                            $jku = Tool::$defaultTool->jku;
                        } else {
                            $privateKey = $this->rsaKey;
                            $kid = $this->kid;
                            $jku = $this->jku;
                        }
                        $payload['iss'] = $this->platformId;
                        $payload['aud'] = array($this->key);
                        $payload['azp'] = $this->key;
                        $payload[Util::JWT_CLAIM_PREFIX . '/claim/deployment_id'] = $this->deploymentId;
                        $paramName = 'id_token';
                    }
                    $payload['nonce'] = $nonce;
                    $payload[Util::JWT_CLAIM_PREFIX . '/claim/target_link_uri'] = $endpoint;
                }
            } else {
                $ok = true;
                if ($this instanceof Tool) {
                    $iss = $this->baseUrl;
                    $authorizationId = $this->platform->authorizationServerId;
                    $privateKey = $this->rsaKey;
                    $kid = $this->kid;
                    $jku = $this->jku;
                } else {
                    if (!empty(Tool::$defaultTool)) {
                        $iss = Tool::$defaultTool->baseUrl;
                        $privateKey = Tool::$defaultTool->rsaKey;
                        $kid = Tool::$defaultTool->kid;
                        $jku = Tool::$defaultTool->jku;
                    } else {
                        $iss = $this->platformId;
                        $privateKey = $this->rsaKey;
                        $kid = $this->kid;
                        $jku = $this->jku;
                    }
                    $authorizationId = $this->authorizationServerId;
                }
                $payload['iss'] = $iss;
                $payload['sub'] = $this->key;
                if (empty($authorizationId)) {
                    $authorizationId = $endpoint;
                }
                $payload['aud'] = array($authorizationId);
                $payload['jti'] = $nonce;
                $params = $data;
                $paramName = 'client_assertion';
            }
        }
        if ($ok) {
            if (empty($timestamp)) {
                $timestamp = time();
            }
            $payload['iat'] = $timestamp;
            $payload['exp'] = $timestamp + 60;
            try {
                $jwt = Jwt::getJwtClient();
                $params[$paramName] = $jwt::sign($payload, $this->signatureMethod, $privateKey, $kid, $jku);
            } catch (\Exception $e) {
                $params = array();
            }

            return $params;
        } else {
            $header = '';
            if ($this instanceof Tool) {
                $accessToken = $this->platform->accessToken;
            } else {
                $accessToken = $this->accessToken;
            }
            if (!is_null($accessToken)) {
                $header = "Authorization: Bearer {$accessToken->token}";
            }
            if (empty($data) && ($method !== 'DELETE')) {
                if (!empty($type)) {
                    $header .= "\nAccept: {$type}";
                }
            } elseif (isset($type)) {
                $header .= "\nContent-Type: {$type}";
                if (!empty($data) && is_string($data)) {
                    $header .= "\nContent-Length: " . strlen($data);
                }
            }

            return $header;
        }
    }

}
