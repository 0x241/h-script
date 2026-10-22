<?php

namespace HScript\Finance;

use HScript\Security\SessionCsrf;
use HScript\Template\View;
use InvalidArgumentException;

/** Repeatable financial forms retain owner/CSRF/captcha authorization. */
final class FinancialForm
{
    public static function fields(): string
    {
        $key = bin2hex(random_bytes(24));
        $csrf = SessionCsrf::token();
        return '<input type="hidden" name="financial_key" value="' . $key . '"><input type="hidden" name="session_csrf" value="' . $csrf . '">';
    }

    public static function check(string $action, string $form = ''): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !SessionCsrf::valid($_POST['session_csrf'] ?? null)) {
            http_response_code(403);
            xSysStop('Security: Wrong financial form token', true);
        }
        $key = $_POST['financial_key'] ?? '';
        try { IdempotencyRequest::validate('web:' . $action, is_string($key) ? $key : '', (int)_uid()); }
        catch (InvalidArgumentException $exception) {
            http_response_code(400);
            xSysStop('Invalid financial submission key', true);
        }
        $certificate = $_POST['__Cert'] ?? '';
        if (!is_string($certificate) || !preg_match('/^[a-f0-9]{64}$/D', $certificate)) {
            http_response_code(403);
            xSysStop('Security: Wrong financial form certificate', true);
        }
        $identity = hash('sha256', $action . "\0" . $_POST['financial_key'] . "\0" . $certificate);
        if (!isset($_SESSION['_financial_certificates'][$identity])) {
            View::checkFormSecurity($form);
            $_SESSION['_financial_certificates'][$identity] = true;
            $_SESSION['_financial_certificates'] = array_slice($_SESSION['_financial_certificates'], -100, null, true);
        }
    }

    public static function execute(string $action, array $normalized, callable $work): mixed
    {
        global $db;
        try {
            $result = (new IdempotencyStore($db))->execute('web:' . $action, (string)$_POST['financial_key'], (int)_uid(), IdempotencyRequest::fingerprint($normalized), static function () use ($work): IdempotencyResult {
                $value = FinancialOperationException::check($work());
                return new IdempotencyResult(['result' => $value]);
            });
            return $result->data['result'];
        } catch (IdempotencyConflict $exception) {
            http_response_code(409);
            xSysStop('Financial submission key was already used with different input', true);
        } catch (FinancialOperationException $exception) {
            return $exception->getMessage();
        }
    }
}
