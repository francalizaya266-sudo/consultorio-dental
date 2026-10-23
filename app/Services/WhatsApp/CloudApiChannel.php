<?php

namespace App\Services\WhatsApp;

use App\Models\MessageTemplate;
use App\Models\WhatsappMessage;
use App\Support\Phone;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envío por la API oficial de WhatsApp (Meta Cloud API). Etapa 2.
 *
 * Meta exige plantillas aprobadas para escribir primero al paciente: cada
 * MessageTemplate guarda el nombre de su plantilla aprobada y las variables
 * se envían en el mismo orden en que aparecen en el texto ({nombre}, {fecha}…).
 */
class CloudApiChannel
{
    /** @return string Id del mensaje en Meta. */
    public function send(WhatsappMessage $message, string $renderedBody, array $params): string
    {
        $config = config('consultorio.whatsapp.cloud_api');

        if (empty($config['token']) || empty($config['phone_number_id'])) {
            throw new RuntimeException('Falta configurar el token y el número de la API de WhatsApp.');
        }

        $template = MessageTemplate::query()->where('key', $message->template_key)->first();

        $payload = $template?->api_template_name
            ? $this->templatePayload($message, $template, $params)
            : [
                'messaging_product' => 'whatsapp',
                'to' => Phone::forWhatsApp($message->phone),
                'type' => 'text',
                'text' => ['body' => $renderedBody, 'preview_url' => true],
            ];

        $response = Http::withToken($config['token'])
            ->acceptJson()
            ->post("https://graph.facebook.com/{$config['version']}/{$config['phone_number_id']}/messages", $payload)
            ->throw();

        return (string) $response->json('messages.0.id');
    }

    private function templatePayload(WhatsappMessage $message, MessageTemplate $template, array $params): array
    {
        preg_match_all('/\{(\w+)\}/', $template->body, $matches);

        $parameters = collect($matches[1])
            ->unique()
            ->map(fn (string $name) => ['type' => 'text', 'text' => (string) ($params[$name] ?? '')])
            ->values()
            ->all();

        return [
            'messaging_product' => 'whatsapp',
            'to' => Phone::forWhatsApp($message->phone),
            'type' => 'template',
            'template' => [
                'name' => $template->api_template_name,
                'language' => ['code' => $template->api_language],
                'components' => [['type' => 'body', 'parameters' => $parameters]],
            ],
        ];
    }
}
