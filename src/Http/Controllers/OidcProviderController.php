<?php

declare(strict_types=1);

namespace Sso\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Sso\Models\Identity;
use Sso\Models\OidcProvider;
use Sso\ProviderRegistry;
use Sso\SsoRoutes;

final class OidcProviderController
{
    /**
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'data' => OidcProvider::query()->orderBy('id')->get()->map($this->resource(...))->all(),
            'meta' => ['callback_base' => url(SsoRoutes::PREFIX)],
        ]);
    }

    /**
     * @param Request
     * @param ProviderRegistry
     * @return JsonResponse
     */
    public function store(Request $request, ProviderRegistry $providers): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{0,26}$/', Rule::unique('sso_oidc_providers', 'slug')],
            'client_secret' => ['required', 'string', 'max:1024'],
            ...$this->rules(),
        ]);

        if ($providers->find(OidcProvider::PREFIX.$data['slug']) !== null) {
            throw ValidationException::withMessages(['slug' => 'That identifier is already in use.']);
        }

        return new JsonResponse(['data' => $this->resource(OidcProvider::query()->create($this->attributes($data)))], 201);
    }

    /**
     * @param Request
     * @param OidcProvider
     * @return JsonResponse
     */
    public function update(Request $request, OidcProvider $oidcProvider): JsonResponse
    {
        $data = $request->validate([
            'client_secret' => ['nullable', 'string', 'max:1024'],
            ...$this->rules(),
        ]);

        $attributes = $this->attributes($data);
        if (($data['client_secret'] ?? null) === null || $data['client_secret'] === '') {
            unset($attributes['client_secret']);
        }

        $oidcProvider->update($attributes);
        Cache::forget('sso:oidc:discovery:'.sha1(rtrim($oidcProvider->issuer, '/')));

        return new JsonResponse(['data' => $this->resource($oidcProvider)]);
    }

    /**
     * Linked identities go with the server, so a record created later under the
     * same identifier cannot inherit them.
     *
     * @param OidcProvider
     * @return Response
     */
    public function destroy(OidcProvider $oidcProvider): Response
    {
        Identity::query()->where('provider', $oidcProvider->providerId())->delete();
        $oidcProvider->delete();

        return new Response('', 204);
    }

    /**
     * @return array
     */
    private function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:64'],
            'issuer' => ['required', 'string', 'url:https', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'scopes' => ['nullable', 'string', 'max:255'],
            'groups_claim' => ['nullable', 'string', 'max:64'],
            'admin_groups' => ['nullable', 'array', 'max:50'],
            'admin_groups.*' => ['string', 'max:255'],
            'member_groups' => ['nullable', 'array', 'max:50'],
            'member_groups.*' => ['string', 'max:255'],
            'create_users' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    /**
     * @param array
     * @return array
     */
    private function attributes(array $data): array
    {
        return [
            ...array_intersect_key($data, array_flip(['slug', 'label', 'issuer', 'client_id', 'client_secret'])),
            'scopes' => mb_trim($data['scopes'] ?? '') !== '' ? mb_trim($data['scopes']) : 'openid email profile',
            'groups_claim' => mb_trim($data['groups_claim'] ?? '') !== '' ? mb_trim($data['groups_claim']) : 'groups',
            'admin_groups' => array_values($data['admin_groups'] ?? []),
            'member_groups' => array_values($data['member_groups'] ?? []),
            'create_users' => ($data['create_users'] ?? false) === true,
            'enabled' => ($data['enabled'] ?? true) === true,
        ];
    }

    /**
     * The secret is never returned, only whether one is set.
     *
     * @param OidcProvider
     * @return array
     */
    private function resource(OidcProvider $record): array
    {
        return [
            'id' => $record->id,
            'slug' => $record->slug,
            'provider_id' => $record->providerId(),
            'label' => $record->label,
            'issuer' => $record->issuer,
            'client_id' => $record->client_id,
            'has_secret' => $record->client_secret !== '',
            'scopes' => $record->scopes,
            'groups_claim' => $record->groups_claim,
            'admin_groups' => $record->admin_groups,
            'member_groups' => $record->member_groups,
            'create_users' => $record->create_users,
            'enabled' => $record->enabled,
            'callback_url' => SsoRoutes::callback($record->providerId()),
        ];
    }
}
