<?php
final class LlmService
{
    public function __construct(private Database $db, private string $appKey) {}

    public function run(int $providerId, string $model, string $systemPrompt, string $userPrompt, int $userId): array
    {
        $provider = $this->db->one('SELECT p.*, c.encrypted_secret FROM llm_providers p LEFT JOIN llm_credentials c ON c.provider_id=p.id WHERE p.id=? AND p.enabled=1', [$providerId]);
        if (!$provider) throw new RuntimeException('LLM provider is disabled or not found.');
        if (empty($provider['encrypted_secret']) && $provider['provider_type'] !== 'local') throw new RuntimeException('No API key is configured for this provider.');
        $key = !empty($provider['encrypted_secret']) ? Security::decrypt($provider['encrypted_secret'], $this->appKey) : '';
        $model = trim($model) ?: trim((string)$provider['default_model']);
        if ($model === '') throw new RuntimeException('Select a model before running the LLM.');
        $start = microtime(true);
        try {
            $result = match ($provider['provider_type']) {
                'anthropic' => $this->callAnthropic($provider, $key, $model, $systemPrompt, $userPrompt),
                default => $this->callOpenAICompatible($provider, $key, $model, $systemPrompt, $userPrompt),
            };
            $this->log($providerId, 'operations_assistant', $model, $userId, $result['input_tokens'] ?? null, $result['output_tokens'] ?? null, (int)((microtime(true)-$start)*1000), 'success', null);
            return $result;
        } catch (Throwable $e) {
            $this->log($providerId, 'operations_assistant', $model, $userId, null, null, (int)((microtime(true)-$start)*1000), 'error', substr($e->getMessage(),0,500));
            throw $e;
        }
    }

    private function callOpenAICompatible(array $provider, string $key, string $model, string $systemPrompt, string $userPrompt): array
    {
        $base = rtrim((string)$provider['base_url'], '/');
        $url = $base . '/chat/completions';
        $headers = ['Content-Type: application/json'];
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $payload = [
            'model' => $model,
            'messages' => [
                ['role'=>'system','content'=>$systemPrompt],
                ['role'=>'user','content'=>$userPrompt],
            ],
            'temperature' => 0.2,
        ];
        $json = $this->httpPostJson($url, $headers, $payload, (int)$provider['timeout_seconds']);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if (!is_string($text) || $text === '') throw new RuntimeException('LLM returned no response text.');
        return ['text'=>$text,'input_tokens'=>$json['usage']['prompt_tokens']??null,'output_tokens'=>$json['usage']['completion_tokens']??null];
    }

    private function callAnthropic(array $provider, string $key, string $model, string $systemPrompt, string $userPrompt): array
    {
        $url = rtrim((string)$provider['base_url'], '/') . '/v1/messages';
        $headers = ['Content-Type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'];
        $payload = ['model'=>$model,'max_tokens'=>1000,'system'=>$systemPrompt,'messages'=>[['role'=>'user','content'=>$userPrompt]]];
        $json = $this->httpPostJson($url, $headers, $payload, (int)$provider['timeout_seconds']);
        $text = $json['content'][0]['text'] ?? null;
        if (!is_string($text) || $text === '') throw new RuntimeException('LLM returned no response text.');
        return ['text'=>$text,'input_tokens'=>$json['usage']['input_tokens']??null,'output_tokens'=>$json['usage']['output_tokens']??null];
    }

    private function httpPostJson(string $url, array $headers, array $payload, int $timeout): array
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for LLM requests.');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => min($timeout,10),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('LLM request failed: '.$error);
        $json = json_decode($body,true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($json) ? ($json['error']['message'] ?? $json['message'] ?? 'Provider request failed') : 'Provider request failed';
            throw new RuntimeException('LLM provider error ('.$status.'): '.substr((string)$message,0,300));
        }
        if (!is_array($json)) throw new RuntimeException('LLM provider returned invalid JSON.');
        return $json;
    }

    private function log(int $providerId, string $feature, string $model, int $userId, ?int $inTokens, ?int $outTokens, int $latency, string $status, ?string $error): void
    {
        $this->db->insert('INSERT INTO llm_usage_logs (provider_id,feature_key,model,user_id,input_tokens,output_tokens,latency_ms,status,error_message) VALUES (?,?,?,?,?,?,?,?,?)', [$providerId,$feature,$model,$userId,$inTokens,$outTokens,$latency,$status,$error]);
    }
}
