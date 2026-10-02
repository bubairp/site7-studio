<?php

namespace site7\studio\console\controllers;

use craft\console\Controller;
use site7\studio\services\publishing\Ed25519PackageSigner;
use site7\studio\Site7Studio;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Package signing tools (docs/47_PACKAGE_SIGNING.md). keygen and sign run on
 * the signing host (Commerce24, or the reference for its implementation);
 * verify runs anywhere.
 */
class SigningController extends Controller
{
    /**
     * Prints a new Ed25519 key pair. Keep the secret key out of git.
     * Usage: php craft site7-studio/signing/keygen
     */
    public function actionKeygen(): int
    {
        $pair = Ed25519PackageSigner::generateKeyPair();

        $this->stdout("Public key (give to sites as trustedSigningKeys, or add to BUILT_IN_KEYS):\n  {$pair['publicKey']}\n");
        $this->stdout("Secret key (signing host only, as " . Ed25519PackageSigner::ENV_SECRET_KEY . "):\n  {$pair['secretKey']}\n");

        return ExitCode::OK;
    }

    /**
     * Signs a .s7pkg in place. Needs SITE7_SIGNING_SECRET_KEY and SITE7_SIGNING_KEY_ID.
     * Usage: php craft site7-studio/signing/sign path/to/package.s7pkg
     */
    public function actionSign(string $path): int
    {
        $signature = Site7Studio::getInstance()->packageSigner->sign($path);
        if ($signature === null) {
            $this->stderr('Not signed: set ' . Ed25519PackageSigner::ENV_SECRET_KEY . ' and ' . Ed25519PackageSigner::ENV_KEY_ID . ".\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $this->stdout("Signed {$path}\n", Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Checks a .s7pkg's signature against this site's trusted keys.
     * Usage: php craft site7-studio/signing/verify path/to/package.s7pkg
     */
    public function actionVerify(string $path): int
    {
        /** @var Ed25519PackageSigner $signer */
        $signer = Site7Studio::getInstance()->packageSigner;
        $result = $signer->verifyArchive($path);

        $this->stdout("{$result->status}: {$result->message}\n", $result->isVerified() ? Console::FG_GREEN : Console::FG_YELLOW);
        return $result->isVerified() ? ExitCode::OK : ExitCode::DATAERR;
    }
}
