<?php
/**
 * Per-tenant sign-in codes: generation, normalisation, uniqueness and lookup.
 */
use Resto\Tenancy\Resolver;
use Resto\Tenancy\TenantCode;
use Resto\Tenancy\TenantRepository;

return function (TestRunner $t): void {
    $t->suite('tenant_code', function (TestRunner $t) {
        $code = TenantCode::generate();

        $t->same(TenantCode::length(), strlen($code), 'generated codes match the configured length');
        $t->ok(TenantCode::isWellFormed($code), 'generated codes are well formed');
        $t->ok(
            preg_match('/^[2-9A-HJKMNP-TV-Z]+$/', $code) === 1,
            'codes use the unambiguous alphabet (no 0/O, 1/I/L, U)'
        );
        $t->same('K7M2Q', TenantCode::normalise(' k7m-2q '), 'normalise() upper-cases and strips separators');
        $t->ok(!TenantCode::isWellFormed('0O1IL'), 'ambiguous characters are rejected');
        $t->ok(!TenantCode::isWellFormed('AB1'), 'codes shorter than four characters are rejected');
        $t->ok(TenantCode::isWellFormed(TenantCode::normalise('a-b-c-d-e-f')), 'longer codes are accepted');

        // Codes are minted randomly; a batch must never repeat.
        $minted = [];
        for ($i = 0; $i < 100; $i++) {
            $minted[TenantCode::generate()] = true;
        }
        $t->same(100, count($minted), '100 minted codes are all distinct');

        // Accounts created through the repository always get a code.
        $repo = new TenantRepository();
        $temp = $repo->create([
            'name'        => 'Code Check Restaurant',
            'owner_email' => 'code-check@example.test',
        ]);
        $t->ok(TenantCode::isWellFormed($temp->accessCode()), 'a new account is assigned a sign-in code');
        $t->ok(!$repo->accessCodeExists($temp->accessCode(), $temp->id()), 'the assigned code belongs to nobody else');
        $t->ok(TenantCode::isWellFormed($repo->rotateAccessCode($temp->id())->accessCode()), 'rotating issues a fresh code');
        $repo->delete($temp->id());

        // Lookup against the platform database (skip when nothing is seeded).
        $first = $repo->all([], 'id', 'ASC', 1)[0] ?? null;
        if ($first === null) {
            $t->ok(true, 'no tenants seeded yet — code lookup not exercised');
            return;
        }

        $stored = $first->accessCode();
        $t->same($first->id(), $repo->findByCode($stored)?->id(), 'findByCode() resolves a stored code');
        $t->same($first->id(), $repo->findByCode(strtolower($stored))?->id(), 'lookup ignores letter case');
        $t->same($first->id(), $repo->findByCode(' ' . $stored . '- ')?->id(), 'lookup tolerates spaces and dashes');
        $t->ok($repo->findByCode('ZZZZZ') === null, 'unknown codes resolve to nothing');
        $t->ok(!$repo->accessCodeExists($repo->uniqueAccessCode()), 'uniqueAccessCode() returns an unused code');

        // Single-host installs require the code at sign-in; host-routed ones do not.
        $t->ok(is_bool(Resolver::hostIdentifiesTenant()), 'hostIdentifiesTenant() answers for the current host');
    });
};
