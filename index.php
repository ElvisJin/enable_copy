<?php

require('./vendor/autoload.php');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$slapi = null;

class JWTPayload {
  public $headers;
  public $claims;
  public $signing_input;
  public $signature;

  public function __construct($headers, $claims, $signing_input = null, $signature = null) {
    $this->headers = $headers;
    $this->claims = $claims;
    $this->signing_input = $signing_input;
    $this->signature = $signature;
  }

  static public function from_string($str) {
    $splits = explode('.', $str);
    $len = count($splits);
    if (count($splits) < 3) return null; // error
    $crypto_segment = $splits[$len - 1]; // the last segment is splits
    $signing_input = implode('.', array_slice($splits, 0, $len - 1));
    $payload_segment = $splits[$len - 2];
    $header_segment = implode('.', array_slice($splits, 0, $len - 2));

    $header_data = base64_decode($header_segment);
    $header = json_decode($header_data);
    $payload = base64_decode($payload_segment);
    $signature = base64_decode($crypto_segment);

    return $payload;
  }
}

class ShortLetsAPI {
  public $endpoint;
  public $key_id;
  public $client_id;
  public $my_private_key;
  public $others_public_key;

  public function __construct($settings) {
    $this->endpoint = isset($settings['endpoint']) ? $settings['endpoint'] : 'https://api.klevio.com/s1/v1/rpc';
    $this->key_id = $settings['external_id'];
    $this->client_id = $settings['client_id'];
    $this->my_private_key = $settings['my_private_key'];
    $this->others_public_key = $settings['others_public_key'];
  }

  public function api_operation_payload($method, $params) {
    $now = time();
    $rpc_id = microtime(true) * 100000;

    // for debug
    $now = 1675011709;
    $rpc_id = 167501170986717;

    $rpc = [
      'id' => $rpc_id,
      'methods' => $method,
      'params' => $params
    ];
    $jwt_headers = [
      'alg' => 'ES256',
      'typ' => 'JWT',
      'kid' => $this->key_id
    ];
    $jwt_payload = [
      'iss' => $this->client_id,
      'aud' => 'klevio-api/v1',
      'iat'=> $now,
      'jti'=> 'jti-' . $now,
      'exp' => $now + 5,
      'rpc' => $rpc
    ];

    return new JWTPayload($jwt_headers, $jwt_payload);
  }

  public function key_operation_payload($method, $key_id) {
    return $this->api_operation_payload($method, [ 'key' => $key_id ]);
  }

  public function call($jwt_payload) {
    var_dump($jwt_payload->claims);
    $jwt_content = JWT::encode(
      $jwt_payload->claims, $this->my_private_key, 'ES256', $jwt_payload->headers['kid'], $jwt_payload->headers
    );
    $http_headers = [
      'X-KeyID' => $this->key_id,
      'Content-Type' => 'application/jwt'
    ];

    try {
      echo '<br>endpoint<br>';
      var_dump($this->endpoint);
      echo '<br>headers<br>';
      var_dump($http_headers);
      echo '<br>content<br>';
      var_dump($jwt_content);
      $ch = curl_init($this->endpoint);
      curl_setopt($ch, CURLOPT_HEADER, $http_headers);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $jwt_content);

      $response = json_decode(curl_exec($ch), true);
      curl_close($ch);

      echo 'response from request<br>';
      var_dump($response);
    } 
    catch (exception $ex) {
      // log
      return [
        'error' => true,
        'message' => 'Upstream error1'
      ];
    }

    if ($response->status_code == 200) {
      $jwt_response = $response->text;
      // log
      try {
        $jwt_response_object = JWT::decode(
          $jwt_response, $this->others_public_key, 'EC256'
        );
        $jwt_payload = JWTPayload::from_string($jwt_response);
        return [$jwt_response_object->rpc->result, $jwt_payload];
      } 
      catch (exception $ex) {
        return [
          'error' => true,
          'message' => 'Upstream error 2'
        ];
      }
    } 
    else {
      $body = $response->text;
      // log
      try {
        // $jwt = new JOSE_JWT($response->text);
        $body = JWT::decode(
          $response->text, $this->others_public_key, 'ES256'
        );
      }
      catch (exception $ex) {
        // log
        // pass
        // log
        echo "Upstream error (code={$response->status_code}, body={$body}";
        return [
          'error' => true,
          'message' => $body['rpc']['error']['message']
        ];
      }
    }
  }

  public function read_perms_key($perms_key_id) {
    $jwt_payload = $this->key_operation_payload('getKey', $perms_key_id);
    $res = $this->call($jwt_payload);
    return $res;
  }

  public function delete_perms_key($perms_key_id) {
    $jwt_payload = $this->key_operation_payload('deleteKey', $perms_key_id);
    $res = $this->call($jwt_payload);
    return $res;
  }

  public function grant_key($property_id, $email, $valid_from = null, $valid_to = null) {
    global $slapi;
    
    $validity = ['from' => $valid_from];
    if ($valid_to) $validity['to'] = $valid_to;
    
    if (isset($slapi) && $slapi)
      return $slapi->call($slapi->api_operation_payload('grantKey', [
        'source' => ['$type' => 'property', 'id' => $property_id],
        'user' => ['$type' => 'user', 'email' => $email],
        'validity' => $validity
      ]));
    return null;
  }

  static public function keyEnable() {
    global $slapi;

    $slapi = new ShortLetsAPI([
      'endpoint' => 'https://api.klevio.com/s1/v1/rpc',
      'external_id' => 'P1C32FV59XR8DK9MFX74M50PZ9MEAZ329NTJGE3HMA',
      'client_id' => 'C4FVY1FMYWW60K05F8NS91A2JDFAPA',
      'my_private_key' => '-----BEGIN EC PRIVATE KEY-----
MHcCAQEEICiySHKRnum6j4bqCnB2EPRoqEFMY6C96qucfMfiw+8loAoGCCqGSM49
AwEHoUQDQgAEh4zrdxjdHYESXePAJJGkQ4yJXsJyftVFn0Y45MsPEM+y38S8be6j
+eDNXRB9VQGvuX3ONopgxsBTGXaicOmciQ==
-----END EC PRIVATE KEY-----',
      'others_public_key' => '-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEq5YAUxJ8BePUBVrxEhXJkFwGNuxM
XCV2mfyFtrwlexnf3+kWPmY6dQDPWBT+G5oeVWfCIstmuGJEZGN2cSXDAw==
-----END PUBLIC KEY-----'
    ]);

    $email = 'family@romahi.com';
    $checkin = '2023-06-04T16:00:00Z';
    $checkout = '2023-06-08T10:30:00Z';

    $ret1 = $slapi->grant_key('MainGateCedarHollow', $email, $checkin, $checkout);

    echo '<br>result<br>';
    var_dump($ret1);
  }
}

ShortLetsAPI::keyEnable();