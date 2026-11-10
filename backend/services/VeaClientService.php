<?php

namespace app\services;

/** Direct cURL client for the SenSource Vea API. */
class VeaClientService
{
    private const TOKEN_URL = 'https://auth.sensourceinc.com/oauth/token';
    private const API_BASE_URL = 'https://vea.sensourceinc.com/api';

    private $clientId;
    private $clientSecret;
    private $transport;
    private $token;

    public function __construct(?callable $transport = null)
    {
        $this->clientId = getenv('VEA_CLIENT_ID');
        $this->clientSecret = getenv('VEA_CLIENT_SECRET');
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return is_string($this->clientId) && $this->clientId !== ''
            && is_string($this->clientSecret) && $this->clientSecret !== '';
    }

    /** @return array<int, array<string, mixed>> */
    public function getZones(): array
    {
        $response = $this->send(self::API_BASE_URL . '/zone', [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken(),
            ],
        ]);
        $this->assertSuccessfulDataResponse($response);

        $decoded = json_decode($response['body']);
        if (!is_array($decoded)) {
            throw new \RuntimeException('vea_error');
        }
        $zones = [];
        foreach ($decoded as $zone) {
            if (!$zone instanceof \stdClass) {
                throw new \RuntimeException('vea_error');
            }
            $zones[] = (array)$zone;
        }
        return $zones;
    }

    private function accessToken(): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('not_configured');
        }
        if ($this->token !== null) {
            return $this->token;
        }

        $body = json_encode([
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $response = $this->send(self::TOKEN_URL, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $status = $response['httpCode'];
        if (in_array($status, [400, 401, 403], true)) {
            throw new \RuntimeException('auth_failed');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('vea_error');
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['access_token'])
            || !is_string($data['access_token']) || $data['access_token'] === '') {
            throw new \RuntimeException('auth_failed');
        }

        $this->token = $data['access_token'];
        return $this->token;
    }

    private function assertSuccessfulDataResponse(array $response): void
    {
        $status = $response['httpCode'];
        if (in_array($status, [401, 403], true)) {
            throw new \RuntimeException('auth_failed');
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('vea_error');
        }
    }

    private function send(string $url, array $options): array
    {
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_TIMEOUT] = 20;
        $options[CURLOPT_CONNECTTIMEOUT] = 10;
        $options[CURLOPT_SSL_VERIFYPEER] = true;
        $options[CURLOPT_SSL_VERIFYHOST] = 2;

        try {
            if ($this->transport !== null) {
                $result = call_user_func($this->transport, $url, $options);
            } else {
                $handle = curl_init($url);
                if ($handle === false) {
                    throw new \RuntimeException('unreachable');
                }
                curl_setopt_array($handle, $options);
                $body = curl_exec($handle);
                $result = [
                    'body' => $body,
                    'httpCode' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
                    'curlError' => curl_error($handle),
                ];
                curl_close($handle);
            }
        } catch (\Throwable $error) {
            throw new \RuntimeException('unreachable');
        }

        if (!is_array($result) || !array_key_exists('body', $result)
            || !isset($result['httpCode']) || !isset($result['curlError'])) {
            throw new \RuntimeException('unreachable');
        }
        if ($result['body'] === false || $result['curlError'] !== '') {
            throw new \RuntimeException('unreachable');
        }
        if (!is_string($result['body'])) {
            throw new \RuntimeException('vea_error');
        }

        return [
            'body' => $result['body'],
            'httpCode' => (int)$result['httpCode'],
            'curlError' => '',
        ];
    }
}
