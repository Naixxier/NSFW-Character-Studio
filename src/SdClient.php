<?php

declare(strict_types=1);

final class SdClient
{
    public function status(): array
    {
        try {
            $options = HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/options', 6);
            return [
                'ok' => true,
                'base_url' => Config::sdBaseUrl(),
                'checkpoint' => $options['sd_model_checkpoint'] ?? null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'base_url' => Config::sdBaseUrl(), 'error' => $e->getMessage()];
        }
    }

    public function resources(): array
    {
        return [
            'models' => HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/sd-models', 10),
            'samplers' => HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/samplers', 10),
            'schedulers' => $this->safeGet('/sdapi/v1/schedulers'),
            'loras' => HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/loras', 10),
            'options' => HttpClient::getJson(Config::sdBaseUrl() . '/sdapi/v1/options', 10),
        ];
    }

    public function upscalers(): array
    {
        $names = [];
        foreach ($this->safeGet('/sdapi/v1/latent-upscale-modes') as $item) {
            $name = is_array($item) ? (string) ($item['name'] ?? '') : (string) $item;
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        foreach ($this->safeGet('/sdapi/v1/upscalers') as $item) {
            $name = is_array($item) ? (string) ($item['name'] ?? '') : (string) $item;
            if ($name !== '' && strtolower($name) !== 'none') {
                $names[$name] = true;
            }
        }

        if (!$names) {
            $names['Latent'] = true;
        }

        $ordered = [];
        foreach (['SwinIR_4x', 'Latent (bicubic antialiased)', 'Latent (antialiased)', 'R-ESRGAN 4x+ Anime6B', 'R-ESRGAN 4x+', 'ESRGAN_4x', 'DAT x2', 'DAT x3', 'DAT x4', 'Latent'] as $preferred) {
            if (isset($names[$preferred])) {
                $ordered[] = $preferred;
                unset($names[$preferred]);
            }
        }

        return ['upscalers' => array_merge($ordered, array_keys($names))];
    }

    private function safeGet(string $path): array
    {
        try {
            $data = HttpClient::getJson(Config::sdBaseUrl() . $path, 10);
            return is_array($data) ? $data : [];
        } catch (Throwable) {
            return [];
        }
    }
}
