<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
namespace Dataphyre\Access;

use Dataphyre\Panel\PanelConfig;
use Dataphyre\Panel\PanelInstance;
use Dataphyre\Panel\PanelPageResult;
use Dataphyre\Panel\PanelRequest;

/**
 * Installs Access-backed authentication pages into a Panel instance.
 *
 * PanelAuth owns the panel-facing lifecycle for sign in, sign out, registration,
 * email verification, password reset, and password change. It keeps those pages
 * hidden from navigation, protects the rest of the panel when configured, and
 * routes all form submissions through a shared CSRF scope.
 */
final class PanelAuth {

	private const CSRF_SCOPE='dp_panel_auth';

	/**
	 * Registers authentication pages and optional panel protection.
	 *
	 * Options are merged with DP_ACCESS_CFG panel_auth defaults. Auth pages remain
	 * accessible to anonymous users while other resources require Auth::check()
	 * when protection is enabled.
	 *
	 * @param PanelInstance $panel Panel instance to extend.
	 * @param array<string, mixed> $options Registration, route, verification, mail, and redirect options.
	 * @return PanelInstance Same panel instance with pages and access_auth config attached.
	 */
	public static function register(PanelInstance $panel, array $options=[]): PanelInstance {
		$options=array_replace(self::config(), $options);
		$pages=self::pageNames($options);
		$panel->registerPage($panel->page($pages['login'])->label('Sign in')->icon('log-in')->hideFromNavigation()->content(fn(PanelRequest $request): mixed => self::login($request, $options)));
		$panel->registerPage($panel->page($pages['logout'])->label('Sign out')->icon('log-out')->hideFromNavigation()->content(fn(PanelRequest $request): mixed => self::logout($request, $options)));
		$panel->registerPage($panel->page($pages['register'])->label('Create account')->icon('user-plus')->hideFromNavigation()->content(fn(PanelRequest $request): mixed => self::registerPage($request, $options)));
		$panel->registerPage($panel->page($pages['verify'])->label('Verify email')->icon('mail-check')->hideFromNavigation()->content(fn(PanelRequest $request): mixed => self::verifyEmail($request, $options)));
		$panel->registerPage($panel->page($pages['password_reset'])->label('Reset password')->icon('key-round')->hideFromNavigation()->content(fn(PanelRequest $request): mixed => self::passwordReset($request, $options)));
		$panel->registerPage($panel->page($pages['password_change'])->label('Change password')->icon('lock-keyhole')->hideFromNavigation()->content(fn(PanelRequest $request): mixed => self::passwordChange($request, $options)));
		if(($options['protect'] ?? true)===true){
			$panel->authorize(static function(string $ability, mixed $resource, mixed $user, PanelRequest $request) use ($pages): bool {
				$name=$request->resourceName();
				if($name!==null && in_array($name, $pages, true)){
					return true;
				}
				return Auth::check();
			});
		}
		return $panel->config('access_auth', $options);
	}

	/**
	 * Renders and processes the panel sign-in page.
	 *
	 * The flow first uses the configured Auth guard, then falls back to the
	 * AccessIdentity repository. When email verification is required, unverified
	 * users are logged out and a fresh verification link is attempted.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return PanelPageResult|array{title:string,content:string} redirect after an accepted session, or the sign-in form with CSRF/verification errors.
	 */
	private static function login(PanelRequest $request, array $options): mixed {
		if(Auth::check()){
			return PanelPageResult::redirect(self::afterLoginUrl($request, $options));
		}
		$error='';
		if($request->method()==='POST'){
			if(!self::validCsrf($request)){
				$error='The form expired. Please try again.';
			}
			else{
				$email=strtolower(trim((string)$request->input('email', '')));
				$password=(string)$request->input('password', '');
				$remember=(string)$request->input('remember', '')==='1';
				if(Auth::attempt(['email'=>$email, 'password'=>$password], $remember) || self::attemptRepositoryLogin($email, $password, $remember)){
					$user=Auth::user() ?? AccessIdentity::findByEmail($email);
					if(($options['require_email_verification'] ?? false)===true && $user!==null && AccessIdentity::emailVerified($user)===false){
						Auth::logout();
						self::sendVerification($user, $options);
						$error='Verify your email address before signing in. A fresh link has been sent if the account can receive mail.';
					}
					else{
						return PanelPageResult::redirect(self::afterLoginUrl($request, $options));
					}
				}
				else{
					$error='Those credentials did not match an account.';
				}
			}
		}
		$links=[
			'Create account'=>self::url(self::pageNames($options)['register']),
			'Reset password'=>self::url(self::pageNames($options)['password_reset']),
		];
		if(($options['require_email_verification'] ?? false)===true){
			$links['Verify email']=self::url(self::pageNames($options)['verify']);
		}
		return [
			'title'=>'Sign in',
			'content'=>self::authShell(
				'Sign in',
				'Use your account to continue.',
				$error,
				'<form method="post" class="dp-panel-auth-form">'
				.self::csrfInput()
				.self::input('email', 'Email', 'email', (string)$request->input('email', ''), true)
				.self::input('password', 'Password', 'password', '', true)
				.'<label class="dp-panel-auth-check"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in</span></label>'
				.'<button class="dp-panel-button dp-panel-button-primary" type="submit">Sign in</button>'
				.'</form>'
				.self::authLinks($links)
			),
		];
	}

	/**
	 * Renders and processes sign-out confirmation.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return PanelPageResult|array{title:string,content:string} redirect after session teardown, or a CSRF-protected sign-out confirmation page.
	 */
	private static function logout(PanelRequest $request, array $options): mixed {
		if($request->method()==='POST' || (string)$request->query('confirm', '')==='1'){
			Auth::logout();
			return PanelPageResult::redirect(self::afterLogoutUrl($options));
		}
		return [
			'title'=>'Sign out',
			'content'=>self::authShell(
				'Sign out',
				'End this Panel session.',
				'',
				'<form method="post" class="dp-panel-auth-form">'.self::csrfInput().'<button class="dp-panel-button dp-panel-button-danger" type="submit">Sign out</button></form>'
			),
		];
	}

	/**
	 * Renders and processes account registration.
	 *
	 * Registration validates email shape, minimum password length, duplicate
	 * email state, repository support, and CSRF before creating an identity.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return PanelPageResult|array{title:string,content:string} redirect/message page after registration handling, or the account form with validation feedback.
	 */
	private static function registerPage(PanelRequest $request, array $options): mixed {
		if(($options['allow_registration'] ?? true)!==true){
			return self::messagePage('Registration unavailable', 'New account creation is not enabled for this panel.');
		}
		$repo=AccessIdentity::repository();
		$error='';
		$notice='';
		if($request->method()==='POST'){
			if(!self::validCsrf($request)){
				$error='The form expired. Please try again.';
			}
			else{
				$name=trim((string)$request->input('name', ''));
				$email=strtolower(trim((string)$request->input('email', '')));
				$password=(string)$request->input('password', '');
				if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
					$error='Enter a valid email address.';
				}
				elseif(strlen($password)<8){
					$error='Use at least 8 characters for the password.';
				}
				elseif($repo->findByEmail($email)!==null){
					$error='An account with that email already exists.';
				}
				elseif(!$repo->canRegister()){
					$error='No identity repository is configured for account creation.';
				}
				else{
					$user=$repo->create(['name'=>$name, 'email'=>$email, 'password'=>$password]);
					if($user===null){
						$error='The account could not be created.';
					}
					else{
						if(($options['require_email_verification'] ?? false)===true){
							self::sendVerification($user, $options);
							$notice='Account created. Check your email to verify the address before signing in.';
						}
						else{
							Auth::login($user);
							return PanelPageResult::redirect(self::afterLoginUrl($request, $options));
						}
					}
				}
			}
		}
		return [
			'title'=>'Create account',
			'content'=>self::authShell(
				'Create account',
				'Start with a name, email, and password.',
				$error,
				($notice!=='' ? '<div class="dp-panel-notice dp-panel-notice-success"><span>'.self::e($notice).'</span></div>' : '')
				.'<form method="post" class="dp-panel-auth-form">'
				.self::csrfInput()
				.self::input('name', 'Name', 'text', (string)$request->input('name', ''), true)
				.self::input('email', 'Email', 'email', (string)$request->input('email', ''), true)
				.self::input('password', 'Password', 'password', '', true)
				.'<button class="dp-panel-button dp-panel-button-primary" type="submit">Create account</button>'
				.'</form>'
				.self::authLinks(['Already have an account'=>self::url(self::pageNames($options)['login'])])
			),
		];
	}

	/**
	 * Renders and processes email verification.
	 *
	 * Token requests consume email_verification tokens once. Non-token POSTs send
	 * a fresh verification link without revealing whether the account exists.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return PanelPageResult|array{title:string,content:string} verification status message or request form that avoids account-existence disclosure.
	 */
	private static function verifyEmail(PanelRequest $request, array $options): mixed {
		$token=trim((string)$request->query('token', $request->input('token', '')));
		if($token!==''){
			$row=AccessIdentity::tokens()->consume('email_verification', $token);
			if($row!==null){
				$user=!empty($row['user_id']) ? AccessIdentity::findById((int)$row['user_id']) : AccessIdentity::findByEmail((string)($row['email'] ?? ''));
				if($user!==null && AccessIdentity::markEmailVerified($user)){
					return self::messagePage('Email verified', 'Your email address is verified. You can continue using the panel.', self::url(self::pageNames($options)['login']), 'Sign in');
				}
			}
			return self::messagePage('Verification link expired', 'Request a new verification link and try again.');
		}
		$error='';
		$notice='';
		if($request->method()==='POST'){
			if(!self::validCsrf($request)){
				$error='The form expired. Please try again.';
			}
			else{
				$email=strtolower(trim((string)$request->input('email', '')));
				$user=Auth::user() ?? AccessIdentity::findByEmail($email);
				if($user!==null){
					self::sendVerification($user, $options);
				}
				$notice='If the account exists, a verification link has been sent.';
			}
		}
		$current=Auth::user();
		return [
			'title'=>'Verify email',
			'content'=>self::authShell(
				'Verify email',
				'Send a fresh verification link.',
				$error,
				($notice!=='' ? '<div class="dp-panel-notice dp-panel-notice-success"><span>'.self::e($notice).'</span></div>' : '')
				.'<form method="post" class="dp-panel-auth-form">'.self::csrfInput()
				.self::input('email', 'Email', 'email', $current!==null ? (AccessIdentity::email($current) ?? '') : (string)$request->input('email', ''), true)
				.'<button class="dp-panel-button dp-panel-button-primary" type="submit">Send verification link</button></form>'
			),
		];
	}

	/**
	 * Renders and processes password reset request and token forms.
	 *
	 * Token submissions consume password_reset tokens once and update the stored
	 * password only after CSRF and minimum length checks pass.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return PanelPageResult|array{title:string,content:string} reset-token form, request form, or completion/error message page.
	 */
	private static function passwordReset(PanelRequest $request, array $options): mixed {
		$token=trim((string)$request->query('token', $request->input('token', '')));
		$error='';
		$notice='';
		if($token!==''){
			if($request->method()==='POST'){
				if(!self::validCsrf($request)){
					$error='The form expired. Please try again.';
				}
				else{
					$password=(string)$request->input('password', '');
					if(strlen($password)<8){
						$error='Use at least 8 characters for the password.';
					}
					else{
						$row=AccessIdentity::tokens()->consume('password_reset', $token);
						$user=$row!==null ? (!empty($row['user_id']) ? AccessIdentity::findById((int)$row['user_id']) : AccessIdentity::findByEmail((string)($row['email'] ?? ''))) : null;
						if($user!==null && AccessIdentity::setPassword($user, $password)){
							return self::messagePage('Password updated', 'Your password has been changed.', self::url(self::pageNames($options)['login']), 'Sign in');
						}
						$error='The reset link is invalid or expired.';
					}
				}
			}
			return [
				'title'=>'Set new password',
				'content'=>self::authShell(
					'Set new password',
					'Choose a fresh password for this account.',
					$error,
					'<form method="post" class="dp-panel-auth-form">'.self::csrfInput().'<input type="hidden" name="token" value="'.self::e($token).'">'
					.self::input('password', 'New password', 'password', '', true)
					.'<button class="dp-panel-button dp-panel-button-primary" type="submit">Update password</button></form>'
				),
			];
		}
		if($request->method()==='POST'){
			if(!self::validCsrf($request)){
				$error='The form expired. Please try again.';
			}
			else{
				$email=strtolower(trim((string)$request->input('email', '')));
				$user=AccessIdentity::findByEmail($email);
				if($user!==null){
					self::sendPasswordReset($user, $options);
				}
				$notice='If the account exists, a reset link has been sent.';
			}
		}
		return [
			'title'=>'Reset password',
			'content'=>self::authShell(
				'Reset password',
				'Send a password reset link to your email.',
				$error,
				($notice!=='' ? '<div class="dp-panel-notice dp-panel-notice-success"><span>'.self::e($notice).'</span></div>' : '')
				.'<form method="post" class="dp-panel-auth-form">'.self::csrfInput()
				.self::input('email', 'Email', 'email', (string)$request->input('email', ''), true)
				.'<button class="dp-panel-button dp-panel-button-primary" type="submit">Send reset link</button></form>'
			),
		];
	}

	/**
	 * Renders and processes password changes for authenticated users.
	 *
	 * Anonymous users are redirected to login with a safe return URL. Authenticated
	 * submissions require CSRF, current password verification, and minimum new
	 * password length.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return PanelPageResult|array{title:string,content:string} login redirect for anonymous users, or the password-change form with validation feedback.
	 */
	private static function passwordChange(PanelRequest $request, array $options): mixed {
		if(!Auth::check()){
			return PanelPageResult::redirect(self::url(self::pageNames($options)['login'], ['return'=>self::currentRelativeUrl()]));
		}
		$user=Auth::user() ?? AccessIdentity::findById((int)(Auth::id() ?? 0));
		$error='';
		$notice='';
		if($request->method()==='POST'){
			if(!self::validCsrf($request)){
				$error='The form expired. Please try again.';
			}
			else{
				$current=(string)$request->input('current_password', '');
				$password=(string)$request->input('password', '');
				if($user===null || !AccessIdentity::verifyPassword($user, $current)){
					$error='The current password is incorrect.';
				}
				elseif(strlen($password)<8){
					$error='Use at least 8 characters for the new password.';
				}
				elseif(AccessIdentity::setPassword($user, $password)){
					$notice='Password changed.';
				}
				else{
					$error='The password could not be changed.';
				}
			}
		}
		return [
			'title'=>'Change password',
			'content'=>self::authShell(
				'Change password',
				'Update the password for your current session.',
				$error,
				($notice!=='' ? '<div class="dp-panel-notice dp-panel-notice-success"><span>'.self::e($notice).'</span></div>' : '')
				.'<form method="post" class="dp-panel-auth-form">'.self::csrfInput()
				.self::input('current_password', 'Current password', 'password', '', true)
				.self::input('password', 'New password', 'password', '', true)
				.'<button class="dp-panel-button dp-panel-button-primary" type="submit">Change password</button></form>'
			),
		];
	}

	/**
	 * Attempts login through the configured AccessIdentity repository.
	 *
	 * @param string $email Normalized email address.
	 * @param string $password Plain password submitted by the user.
	 * @param bool $remember Whether the session should be remembered.
	 * @return bool True when repository credentials are valid and Auth::login succeeds.
	 */
	private static function attemptRepositoryLogin(string $email, string $password, bool $remember): bool {
		$user=AccessIdentity::findByEmail($email);
		if($user===null || AccessIdentity::verifyPassword($user, $password)===false){
			return false;
		}
		return Auth::login($user, $remember);
	}

	/**
	 * Creates and sends an email verification token.
	 *
	 * @param mixed $user Identity object or record.
	 * @param array<string, mixed> $options Panel auth options containing TTL and mail queue settings.
	 * @return bool True when a token is created and mail dispatch reports success.
	 */
	private static function sendVerification(mixed $user, array $options): bool {
		$email=AccessIdentity::email($user);
		$id=AccessIdentity::identifier($user);
		if($email===null){
			return false;
		}
		$token=AccessIdentity::tokens()->create('email_verification', $id, $email, [], (int)($options['verification_ttl'] ?? 86400));
		if($token===null){
			return false;
		}
		$link=self::absoluteUrl(self::url(self::pageNames($options)['verify'], ['token'=>$token['token']]));
		return self::sendAuthMail($email, 'Verify your email address', 'Use this link to verify your email address: '.$link, '<p>Use this link to verify your email address.</p><p><a href="'.self::e($link).'">Verify email</a></p>', $options);
	}

	/**
	 * Creates and sends a password reset token.
	 *
	 * @param mixed $user Identity object or record.
	 * @param array<string, mixed> $options Panel auth options containing TTL and mail queue settings.
	 * @return bool True when a token is created and mail dispatch reports success.
	 */
	private static function sendPasswordReset(mixed $user, array $options): bool {
		$email=AccessIdentity::email($user);
		$id=AccessIdentity::identifier($user);
		if($email===null){
			return false;
		}
		$token=AccessIdentity::tokens()->create('password_reset', $id, $email, [], (int)($options['password_reset_ttl'] ?? 3600));
		if($token===null){
			return false;
		}
		$link=self::absoluteUrl(self::url(self::pageNames($options)['password_reset'], ['token'=>$token['token']]));
		return self::sendAuthMail($email, 'Reset your password', 'Use this link to reset your password: '.$link, '<p>Use this link to reset your password.</p><p><a href="'.self::e($link).'">Reset password</a></p>', $options);
	}

	/**
	 * Sends or queues an authentication email.
	 *
	 * Missing mailer support degrades to false because account flows should still
	 * render a non-enumerating response.
	 *
	 * @param string $email Recipient email.
	 * @param string $subject Message subject.
	 * @param string $text Plain-text body.
	 * @param string $html HTML body.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return bool True when the Mailer result reports success.
	 */
	private static function sendAuthMail(string $email, string $subject, string $text, string $html, array $options): bool {
		if(\dataphyre\core::load_framework_module('mailer')!==true || !class_exists('\Dataphyre\Mailer\Mailer')){
			return false;
		}
		$message=[
			'to'=>$email,
			'subject'=>$subject,
			'text'=>$text,
			'html'=>$html,
			'tags'=>['panel_auth'],
		];
		$result=($options['queue_mail'] ?? true)===true
			? \Dataphyre\Mailer\Mailer::queue($message)
			: \Dataphyre\Mailer\Mailer::send($message);
		return $result->ok();
	}

	/**
	 * Wraps auth form content in the shared panel auth card.
	 *
	 * @param string $title Page title.
	 * @param string $subtitle Supporting copy.
	 * @param string $error Error message to display, or empty string.
	 * @param string $body Trusted form or page body HTML assembled by this class.
	 * @return string Auth card HTML.
	 */
	private static function authShell(string $title, string $subtitle, string $error, string $body): string {
		return '<section class="dp-panel-auth-card">'
			.'<div class="dp-panel-heading-row"><div><p class="dp-panel-kicker">Access</p><h2>'.self::e($title).'</h2><p>'.self::e($subtitle).'</p></div></div>'
			.($error!=='' ? '<div class="dp-panel-notice dp-panel-notice-danger"><span>'.self::e($error).'</span></div>' : '')
			.$body
			.'</section>';
	}

	/**
	 * Builds a simple panel page payload for auth status messages.
	 *
	 * @param string $title Page title.
	 * @param string $message Message body.
	 * @param ?string $url Optional continuation URL.
	 * @param string $label Continuation button label.
	 * @return array{title: string, content: string} Panel page payload.
	 */
	private static function messagePage(string $title, string $message, ?string $url=null, string $label='Continue'): array {
		return [
			'title'=>$title,
			'content'=>'<section class="dp-panel-auth-card"><div class="dp-panel-empty"><strong>'.self::e($title).'</strong><span>'.self::e($message).'</span></div>'
				.($url!==null ? '<div class="dp-panel-toolbar"><a class="dp-panel-button dp-panel-button-primary" href="'.self::e($url).'">'.self::e($label).'</a></div>' : '')
				.'</section>',
		];
	}

	/**
	 * Renders an escaped auth form input.
	 *
	 * @param string $name Input name.
	 * @param string $label Visible field label.
	 * @param string $type HTML input type.
	 * @param string $value Current input value.
	 * @param bool $required Whether the input is required.
	 * @return string Label and input HTML.
	 */
	private static function input(string $name, string $label, string $type, string $value='', bool $required=false): string {
		return '<label class="dp-panel-field"><span>'.self::e($label).'</span><input type="'.self::e($type).'" name="'.self::e($name).'" value="'.self::e($value).'"'.($required ? ' required' : '').'></label>';
	}

	/**
	 * Renders secondary auth navigation links.
	 *
	 * @param array<string, string> $links Link label to URL map.
	 * @return string Navigation HTML or an empty string.
	 */
	private static function authLinks(array $links): string {
		$html='';
		foreach($links as $label=>$url){
			$html.='<a href="'.self::e((string)$url).'">'.self::e((string)$label).'</a>';
		}
		return $html!=='' ? '<nav class="dp-panel-auth-links">'.$html.'</nav>' : '';
	}

	/**
	 * Renders the hidden CSRF token for panel auth forms.
	 *
	 * @return string Hidden CSRF input HTML.
	 */
	private static function csrfInput(): string {
		return '<input type="hidden" name="csrf" value="'.self::e((string)\dataphyre\core::csrf(self::CSRF_SCOPE)).'">';
	}

	/**
	 * Validates the submitted panel auth CSRF token.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @return bool True when the submitted token matches the shared auth scope.
	 */
	private static function validCsrf(PanelRequest $request): bool {
		return \dataphyre\core::csrf(self::CSRF_SCOPE, (string)$request->input('csrf', ''))===true;
	}

	/**
	 * Reads panel auth defaults from DP_ACCESS_CFG.
	 *
	 * @return array<string, mixed> Panel auth configuration or an empty array.
	 */
	private static function config(): array {
		return \defined('DP_ACCESS_CFG') && \is_array(\DP_ACCESS_CFG) && is_array(DP_ACCESS_CFG['panel_auth'] ?? null)
			? DP_ACCESS_CFG['panel_auth']
			: [];
	}

	/**
	 * Resolves and normalizes auth page names from options.
	 *
	 * @param array<string, mixed> $options Panel auth options.
	 * @return array<string, string> Page names keyed by auth flow.
	 */
	private static function pageNames(array $options): array {
		return [
			'login'=>self::pageName((string)($options['login_page'] ?? 'login')),
			'register'=>self::pageName((string)($options['register_page'] ?? 'register')),
			'logout'=>self::pageName((string)($options['logout_page'] ?? 'logout')),
			'verify'=>self::pageName((string)($options['verify_page'] ?? 'email_verification')),
			'password_reset'=>self::pageName((string)($options['password_reset_page'] ?? 'password_reset')),
			'password_change'=>self::pageName((string)($options['password_change_page'] ?? 'password_change')),
		];
	}

	/**
	 * Normalizes one auth page name.
	 *
	 * @param string $name Candidate page name.
	 * @return string Lowercase panel-safe page name.
	 */
	private static function pageName(string $name): string {
		$name=strtolower(trim((string)preg_replace('/[^A-Za-z0-9_]+/', '_', $name), '_'));
		return $name!=='' ? $name : 'login';
	}

	/**
	 * Builds a panel URL for an auth page.
	 *
	 * @param string $page Auth page name.
	 * @param array<string, mixed> $query Query parameters.
	 * @return string Panel URL.
	 */
	private static function url(string $page, array $query=[]): string {
		return PanelConfig::url($page, $query);
	}

	/**
	 * Resolves the post-login redirect target.
	 *
	 * Only safe relative URLs are accepted from request or configuration to avoid
	 * open redirects.
	 *
	 * @param PanelRequest $request Current panel request.
	 * @param array<string, mixed> $options Panel auth options.
	 * @return string Safe relative redirect URL.
	 */
	private static function afterLoginUrl(PanelRequest $request, array $options): string {
		$return=(string)$request->query('return', $request->input('return', ''));
		if(self::safeRelative($return)){
			return $return;
		}
		$configured=$options['after_login'] ?? null;
		return is_string($configured) && self::safeRelative($configured) ? $configured : PanelConfig::url('');
	}

	/**
	 * Resolves the post-logout redirect target.
	 *
	 * @param array<string, mixed> $options Panel auth options.
	 * @return string Safe relative redirect URL.
	 */
	private static function afterLogoutUrl(array $options): string {
		$configured=$options['after_logout'] ?? null;
		return is_string($configured) && self::safeRelative($configured) ? $configured : self::url(self::pageNames($options)['login']);
	}

	/**
	 * Checks whether a redirect URL is relative to this host.
	 *
	 * @param string $url Candidate redirect URL.
	 * @return bool True for non-empty slash-prefixed URLs without scheme or protocol-relative prefix.
	 */
	private static function safeRelative(string $url): bool {
		$url=trim($url);
		return $url!=='' && !str_contains($url, '://') && !str_starts_with($url, '//') && $url[0]==='/';
	}

	/**
	 * Returns the current request URI when it is safe for a return parameter.
	 *
	 * @return string Safe relative current URL or root fallback.
	 */
	private static function currentRelativeUrl(): string {
		$uri=(string)($_SERVER['REQUEST_URI'] ?? '/');
		return self::safeRelative($uri) ? $uri : '/';
	}

	/**
	 * Converts a panel-relative URL to an absolute URL for email links.
	 *
	 * @param string $url Relative or absolute URL.
	 * @return string Absolute URL using the current request scheme and host.
	 */
	private static function absoluteUrl(string $url): string {
		if(str_contains($url, '://')){
			return $url;
		}
		$scheme=((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['REQUEST_SCHEME'] ?? '')==='https')) ? 'https' : 'http';
		$host=(string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
		return $scheme.'://'.$host.'/'.ltrim($url, '/');
	}

	/**
	 * Escapes text for safe HTML output in auth pages and emails.
	 *
	 * @param string $value Raw text.
	 * @return string UTF-8 HTML-escaped text.
	 */
	private static function e(string $value): string {
		return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
