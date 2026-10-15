<?php

namespace site7\studio\services\theme;

use Craft;
use craft\helpers\App;

/**
 * The Theme ships Wheel Form's settings with reCAPTCHA on, reading the keys from
 * .env ($RECAPTCHA_SITE_KEY / $RECAPTCHA_SECRET_KEY), and never copies .env
 * values. Without the keys every submit crashed inside Wheel Form ("Argument #2
 * ($secret) must be of type string, null given"). After a Theme install or update,
 * reCAPTCHA is switched off on the forms while the keys are missing, and the
 * Dashboard says so (docs/43 #57).
 */
class RecaptchaGuard
{
    /** Whether Wheel Form is installed and its reCAPTCHA secret resolves to nothing. */
    public static function keysMissing(): bool
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('wheelform');
        if (!$plugin) {
            return false;
        }
        $settings = $plugin->getSettings();
        $secret = App::parseEnv((string)($settings->recaptcha_secret ?? ''));
        $public = App::parseEnv((string)($settings->recaptcha_public ?? ''));

        return trim((string)$secret) === '' || trim((string)$public) === '' || str_starts_with((string)$secret, '$');
    }

    /** How many forms have reCAPTCHA on. */
    public static function formsUsingIt(): int
    {
        $db = Craft::$app->getDb();
        if (!$db->tableExists('{{%wheelform_forms}}')) {
            return 0;
        }

        return (int)(new \craft\db\Query())->from('{{%wheelform_forms}}')->where(['recaptcha' => 1])->count();
    }

    /**
     * Switches reCAPTCHA off on every form while the keys are missing.
     *
     * @return string|null a note for the install/update report, or null when nothing changed
     */
    public static function switchOffWithoutKeys(): ?string
    {
        if (!self::keysMissing() || ($count = self::formsUsingIt()) === 0) {
            return null;
        }
        Craft::$app->getDb()->createCommand()->update('{{%wheelform_forms}}', ['recaptcha' => 0], ['recaptcha' => 1])->execute();

        return "reCAPTCHA was switched off on {$count} form(s), because RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY aren't set in .env; without them every submit failed. Add the keys, then switch reCAPTCHA back on in Wheel Form → Forms.";
    }
}
