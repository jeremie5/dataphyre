<?php
declare(strict_types=1);
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */

use Dataphyre\Panel\PanelImpersonationSession;
use Dataphyre\Panel\PanelPermissionSimulator;
use Dataphyre\Panel\PanelSecurityAuditTrail;
use Dataphyre\Panel\PanelSecurityContext;
use Dataphyre\Panel\PanelSecurityPolicy;
use Dataphyre\Test\Context;
use function Dataphyre\Test\test;

require_once __DIR__.'/panel_test_harness_helpers.php';
dp_panel_unit_test_bootstrap();

test('security contexts support hierarchical permissions roles tenants and session evidence', static function(Context $t): void {
	$context=PanelSecurityContext::make('operator-1', [
		'tenant_id'=>'tenant-a', 'roles'=>['Operator'], 'permissions'=>['orders.*', 'panel.impersonate'],
		'mfa_level'=>2, 'trusted_session'=>true, 'session_id'=>'session-1', 'attributes'=>['region'=>'ca'],
	]);
	$t->isTrue($context->hasRole('operator'));
	$t->isTrue($context->can('orders.update'));
	$t->isFalse($context->can('sellers.delete'));
	$t->same('tenant-a', $context->tenantId());
	$t->same('ca', $context->attribute('region'));
})->tag('panel', 'security', 'context')->maxMillis(1000);

test('security policies explain roles permissions MFA tenant trust and impersonation denials', static function(Context $t): void {
	$policy=PanelSecurityPolicy::make('orders.capture')
		->roles(['operator', 'admin'])
		->permissions(['orders.capture'])
		->mfa(2)
		->trustedSession()
		->tenant('tenant-a')
		->forbidImpersonation()
		->attribute('region', 'ca');
	$allowed=$policy->evaluate(PanelSecurityContext::make('mina', ['tenant_id'=>'tenant-a', 'roles'=>['operator'], 'permissions'=>['orders.capture'], 'mfa_level'=>2, 'trusted_session'=>true, 'attributes'=>['region'=>'ca']]));
	$t->isTrue($allowed->allowed());
	$denied=$policy->evaluate(PanelSecurityContext::make('iris', ['tenant_id'=>'tenant-b', 'roles'=>['viewer'], 'permissions'=>[], 'mfa_level'=>0, 'impersonator_id'=>'admin']));
	$t->isTrue($denied->denied());
	$t->isTrue(count($denied->reasons())>=5);
	$t->same(2, $denied->requirements()['mfa_level']);
	$t->same('tenant-a', $denied->requirements()['tenant_id']);
})->tag('panel', 'security', 'policy', 'explanations')->maxMillis(1000);

test('impersonation requires permission reason separate target expiry consent and scope', static function(Context $t): void {
	$admin=PanelSecurityContext::make('admin-1', ['permissions'=>['panel.impersonate']]);
	$session=PanelImpersonationSession::start($admin, 'operator-1', 'Support investigation', ['duration_seconds'=>300, 'consented'=>true, 'scopes'=>['read', 'comment']]);
	$t->isTrue($session->active());
	$t->isTrue($session->can('comment'));
	$t->isFalse($session->can('delete'));
	$t->isTrue($session->consented());
	$target=$session->targetContext(['tenant_id'=>'tenant-a']);
	$t->isTrue($target->impersonating());
	$t->same('admin-1', $target->impersonatorId());
	$t->isFalse($session->end()->active());
})->tag('panel', 'security', 'impersonation')->maxMillis(1000);

test('security audit trail persists a verifiable hash chain', static function(Context $t): void {
	$file=$t->workspace('panel-security')->path('audit.json');
	$context=PanelSecurityContext::make('operator', ['permissions'=>['orders.read']]);
	$decision=PanelSecurityPolicy::make('orders.read')->permissions('orders.read')->evaluate($context);
	$trail=new PanelSecurityAuditTrail($file);
	$trail->record('authorization.decision', $context, $decision);
	$trail->record('authorization.decision', $context, $decision, ['resource'=>'orders']);
	$t->isTrue($trail->verify());
	$t->same(2, count($trail->events()));
	$reopened=new PanelSecurityAuditTrail($file);
	$t->isTrue($reopened->verify());
	$t->same(2, $reopened->jsonSerialize()['count']);
})->tag('panel', 'security', 'audit')->maxMillis(2000);

test('permission simulator produces policy matrices and detects tenant boundary leaks', static function(Context $t): void {
	$simulation=PanelPermissionSimulator::simulate([
		'read'=>PanelSecurityPolicy::make('orders.read')->permissions('orders.read'),
		'delete'=>PanelSecurityPolicy::make('orders.delete')->permissions('orders.delete')->mfa(1),
	], [
		'operator'=>PanelSecurityContext::make('operator', ['permissions'=>['orders.read']]),
		'admin'=>PanelSecurityContext::make('admin', ['permissions'=>['orders.*'], 'mfa_level'=>2]),
	]);
	$t->same(3, $simulation['allowed']);
	$t->same(1, $simulation['denied']);
	$audit=PanelPermissionSimulator::auditTenantIsolation([
		['id'=>1, 'tenant_id'=>'tenant-a'], ['id'=>2, 'tenant_id'=>'tenant-b'], ['id'=>3],
	], PanelSecurityContext::make('operator', ['tenant_id'=>'tenant-a']));
	$t->isFalse($audit['passed']);
	$t->same(2, count($audit['issues']));
})->tag('panel', 'security', 'simulation', 'tenancy')->maxMillis(1000);
