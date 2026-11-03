<?php
// TEST-ONLY stand-in for the member system (no database, no Stripe). Never deployed.
declare(strict_types=1);
function sr_config(string $k){return null;}
function sr_current_user(){return ['id'=>1,'name'=>'Test Owner','email'=>'owner@example.test','email_verified_at'=>'2026-01-01'];}
function sr_usage_summary(int $id): array {return ['free_remaining'=>1,'free_limit'=>1,'credits'=>0,'exhausted'=>false,'period'=>['statements_used'=>0,'statement_limit'=>50,'pages_used'=>0,'page_limit'=>2000,'days_remaining'=>20,'ends_at'=>'2026-10-30']];}
function sr_turnstile_config(): array {return [];}
function sr_turnstile_enabled(): bool {return false;}
function sr_csrf_token(): string {return 'test';}
function sr_e(string $v): string {return htmlspecialchars($v, ENT_QUOTES);}
function sr_is_owner(int $id): bool {return true;}
function sr_is_pro_active(int $id): bool {return true;}
function sr_public_header(string $a, $u=null): void {echo '<header class="test-header">Test header</header>';}
function sr_require_full_converter(){return sr_current_user();}
function sr_url(string $p=''): string {return '/'.ltrim($p,'/');}
function sr_usage_setting(string $k, $d=null){return $d;}
