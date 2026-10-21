<?php

namespace Tests\Unit;

use App\Http\Requests\EmailCampaign\StoreEmailCampaignRequest;
use App\Http\Requests\EmailCampaign\UpdateEmailCampaignRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class EmailCampaignRequestRulesTest extends TestCase
{
    private function errores(string $request, array $datos): array
    {
        $r = new $request();

        // sin la regla exists (no hay base de datos en este test)
        $rules = array_filter($r->rules(), fn ($k) => $k !== 'client_ids.*', ARRAY_FILTER_USE_KEY);

        return Validator::make($datos, $rules, $r->messages())->errors()->keys();
    }

    private function base(array $extra = []): array
    {
        return array_merge(['campaign_name' => 'X', 'subject' => 'Y', 'body' => '<p>Z</p>'], $extra);
    }

    /** @dataProvider requests */
    public function test_solo_emails_personalizados_es_valido(string $request): void
    {
        $this->assertSame([], $this->errores($request, $this->base(['custom_emails' => ['a@b.co']])));
    }

    /** @dataProvider requests */
    public function test_sin_clientes_ni_emails_falla(string $request): void
    {
        $e = $this->errores($request, $this->base());
        $this->assertContains('client_ids', $e);
        $this->assertContains('custom_emails', $e);
    }

    /** @dataProvider requests */
    public function test_con_clientes_exige_el_campo_de_email(string $request): void
    {
        $this->assertContains('email_field_type', $this->errores($request, $this->base(['client_ids' => [1]])));
    }

    public static function requests(): array
    {
        return [[StoreEmailCampaignRequest::class], [UpdateEmailCampaignRequest::class]];
    }
}
