<?php
/**
 * Email Service
 * Handles all email sending functionality using SMTP
 * Uses PHPMailer when available, falls back to PHP mail()
 */

// Load Composer autoloader for PHPMailer
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/Logger.php';

class EmailService {
    private static $instance = null;
    private $smtpHost;
    private $smtpPort;
    private $smtpUsername;
    private $smtpPassword;
    private $smtpEncryption;
    private $fromEmail;
    private $fromName;

    /**
     * Private constructor for singleton pattern
     */
    private function __construct() {
        $this->smtpHost = Config::get('SMTP_HOST', 'localhost');
        $this->smtpPort = Config::get('SMTP_PORT', 587);
        $this->smtpUsername = Config::get('SMTP_USERNAME', '');
        $this->smtpPassword = Config::get('SMTP_PASSWORD', '');
        $this->smtpEncryption = Config::get('SMTP_ENCRYPTION', 'tls');
        $this->fromEmail = Config::get('SMTP_FROM_EMAIL', 'noreply@geobeasts.co.uk');
        $this->fromName = Config::get('SMTP_FROM_NAME', 'GeoBeasts');
    }

    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send email using available method
     */
    public function send($to, $subject, $htmlBody, $textBody = null) {
        // Check if PHPMailer is available
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendWithPhpMailer($to, $subject, $htmlBody, $textBody);
        }

        // PHPMailer is required for AWS SES - do not fall back to mail()
        Logger::error('PHPMailer not available - cannot send email', [
            'to' => $to,
            'subject' => $subject,
            'note' => 'AWS SES requires PHPMailer for SMTP authentication'
        ]);
        return false;
    }

    /**
     * Send using PHPMailer (preferred method)
     */
    private function sendWithPhpMailer($to, $subject, $htmlBody, $textBody) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUsername;
            $mail->Password = $this->smtpPassword;
            $mail->SMTPSecure = $this->smtpEncryption;
            $mail->Port = $this->smtpPort;

            // Recipients
            $mail->setFrom($this->fromEmail, $this->fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($this->fromEmail, $this->fromName);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?? strip_tags($htmlBody);

            $mail->send();

            Logger::info('Email sent successfully', ['to' => $to, 'subject' => $subject]);
            return true;

        } catch (Exception $e) {
            // Get detailed SMTP error if available
            $smtpError = '';
            if (isset($mail)) {
                $smtpError = $mail->ErrorInfo;
            }

            Logger::error('PHPMailer error: ' . $e->getMessage(), [
                'to' => $to,
                'subject' => $subject,
                'smtp_host' => $this->smtpHost,
                'smtp_port' => $this->smtpPort,
                'smtp_encryption' => $this->smtpEncryption,
                'from_email' => $this->fromEmail,
                'error_code' => $e->getCode(),
                'smtp_error' => $smtpError,
                'troubleshooting' => 'If in SES Sandbox mode, both sender and recipient emails must be verified in AWS SES Console'
            ]);
            return false;
        }
    }

    /**
     * Legacy mail() function removed - AWS SES requires PHPMailer
     * The sendWithMail method has been removed as it cannot authenticate with AWS SES
     * All emails must be sent via PHPMailer with SMTP authentication
     */

    /**
     * Send verification email
     */
    public static function sendVerificationEmail($email, $token) {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');
            $verifyUrl = $appUrl . '/verify-email?token=' . urlencode($token);

            $subject = 'Verify your GeoBeasts account';

            $htmlBody = self::getEmailTemplate('verification', [
                'verify_url' => $verifyUrl,
                'token' => $token
            ]);

            $textBody = "Welcome to GeoBeasts!\n\n"
                . "Please verify your email address by visiting:\n"
                . "$verifyUrl\n\n"
                . "This link will expire in 24 hours.\n\n"
                . "If you didn't create a GeoBeasts account, please ignore this email.";

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send verification email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send password reset email
     */
    public static function sendPasswordResetEmail($email, $token) {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');
            $resetUrl = $appUrl . '/reset-password?token=' . urlencode($token);

            $subject = 'Reset your GeoBeasts password';

            $htmlBody = self::getEmailTemplate('password_reset', [
                'reset_url' => $resetUrl,
                'token' => $token
            ]);

            $textBody = "Password Reset Request\n\n"
                . "We received a request to reset your GeoBeasts password.\n\n"
                . "Click here to reset your password:\n"
                . "$resetUrl\n\n"
                . "This link will expire in 1 hour.\n\n"
                . "If you didn't request this, please ignore this email. Your password will remain unchanged.";

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send password reset email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send friend request notification
     */
    public static function sendFriendRequestEmail($email, $fromUsername, $context) {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');

            $subject = "Friend request from $fromUsername on GeoBeasts";

            $htmlBody = self::getEmailTemplate('friend_request', [
                'from_username' => htmlspecialchars($fromUsername),
                'context' => htmlspecialchars($context),
                'app_url' => $appUrl
            ]);

            $textBody = "You have a new friend request on GeoBeasts!\n\n"
                . "$fromUsername wants to be your friend.\n"
                . "Context: $context\n\n"
                . "Log in to GeoBeasts to accept or decline: $appUrl";

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send friend request email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send parent activity summary
     */
    public static function sendParentActivitySummary($email, $childUsername, $activities) {
        try {
            $instance = self::getInstance();

            $subject = "GeoBeasts: Activity summary for $childUsername";

            $htmlBody = self::getEmailTemplate('parent_activity', [
                'child_username' => htmlspecialchars($childUsername),
                'activities' => $activities
            ]);

            $textBody = "Activity summary for $childUsername\n\n";
            foreach ($activities as $activity) {
                $textBody .= "- {$activity['type']}: {$activity['description']} ({$activity['date']})\n";
            }

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send parent activity summary: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send marketplace offer received notification
     */
    public static function sendMarketplaceOfferEmail($email, $sellerUsername, $cardName, $offerPrice, $askingPrice, $buyerUsername) {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');
            $offersUrl = $appUrl . '/marketplace/offers';

            $subject = "New offer on your $cardName listing";

            $htmlBody = self::getEmailTemplate('marketplace_offer', [
                'seller_username' => htmlspecialchars($sellerUsername),
                'card_name' => htmlspecialchars($cardName),
                'offer_price' => number_format($offerPrice, 2),
                'asking_price' => number_format($askingPrice, 2),
                'buyer_username' => htmlspecialchars($buyerUsername),
                'offers_url' => $offersUrl,
                'app_url' => $appUrl
            ]);

            $discount = (($askingPrice - $offerPrice) / $askingPrice) * 100;
            $textBody = "New Marketplace Offer on GeoBeasts!\n\n"
                . "Hi $sellerUsername,\n\n"
                . "$buyerUsername made an offer on your $cardName listing.\n\n"
                . "Asking Price: $askingPrice credits\n"
                . "Offer Price: $offerPrice credits";

            if ($offerPrice < $askingPrice) {
                $textBody .= " (" . number_format($discount, 1) . "% below asking)\n\n";
            } else {
                $textBody .= "\n\n";
            }

            $textBody .= "View and respond to this offer: $offersUrl";

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send marketplace offer email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send marketplace offer accepted notification
     */
    public static function sendOfferAcceptedEmail($email, $buyerUsername, $cardName, $offerPrice) {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');
            $notificationsUrl = $appUrl . '/notifications';

            $subject = "Your offer on $cardName was accepted!";

            $htmlBody = self::getEmailTemplate('offer_accepted', [
                'buyer_username' => htmlspecialchars($buyerUsername),
                'card_name' => htmlspecialchars($cardName),
                'offer_price' => number_format($offerPrice, 2),
                'notifications_url' => $notificationsUrl,
                'app_url' => $appUrl
            ]);

            $textBody = "Great News!\n\n"
                . "Hi $buyerUsername,\n\n"
                . "Your offer of $offerPrice credits on $cardName has been accepted!\n\n"
                . "Log in to GeoBeasts to confirm your purchase and complete the transaction:\n"
                . "$notificationsUrl\n\n"
                . "The card will be added to your collection once you confirm the purchase.";

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send offer accepted email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send marketplace purchase complete notification
     */
    public static function sendPurchaseCompleteEmail($email, $username, $cardName, $price, $isBuyer) {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');

            if ($isBuyer) {
                $subject = "Purchase complete: $cardName";
                $collectionUrl = $appUrl . '/collection';

                $htmlBody = self::getEmailTemplate('purchase_complete', [
                    'username' => htmlspecialchars($username),
                    'card_name' => htmlspecialchars($cardName),
                    'price' => number_format($price, 2),
                    'collection_url' => $collectionUrl,
                    'app_url' => $appUrl
                ]);

                $textBody = "Purchase Complete!\n\n"
                    . "Hi $username,\n\n"
                    . "You successfully purchased $cardName for $price credits!\n\n"
                    . "The card has been added to your collection and stamped with your personal stamp.\n\n"
                    . "View your collection: $collectionUrl";
            } else {
                $subject = "Sale complete: $cardName";

                $htmlBody = self::getEmailTemplate('sale_complete', [
                    'username' => htmlspecialchars($username),
                    'card_name' => htmlspecialchars($cardName),
                    'price' => number_format($price, 2),
                    'app_url' => $appUrl
                ]);

                $textBody = "Sale Complete!\n\n"
                    . "Hi $username,\n\n"
                    . "You successfully sold $cardName for $price credits!\n\n"
                    . "The credits have been added to your account.";
            }

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send purchase complete email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Send offline battle challenge notification
     */
    public static function sendBattleChallengeEmail($email, $defenderUsername, $challengerUsername, $battleType = 'standard') {
        try {
            $instance = self::getInstance();
            $appUrl = Config::get('APP_URL', 'https://geobeasts.co.uk');
            $battlesUrl = $appUrl . '/battles';

            $subject = "$challengerUsername challenged you to a battle!";

            $htmlBody = self::getEmailTemplate('battle_challenge', [
                'defender_username' => htmlspecialchars($defenderUsername),
                'challenger_username' => htmlspecialchars($challengerUsername),
                'battle_type' => htmlspecialchars($battleType),
                'battles_url' => $battlesUrl,
                'app_url' => $appUrl
            ]);

            $textBody = "Battle Challenge!\n\n"
                . "Hi $defenderUsername,\n\n"
                . "$challengerUsername has challenged you to a $battleType battle on GeoBeasts!\n\n"
                . "Log in to accept or decline the challenge:\n"
                . "$battlesUrl\n\n"
                . "Get ready to battle!";

            return $instance->send($email, $subject, $htmlBody, $textBody);
        } catch (Exception $e) {
            Logger::error('Failed to send battle challenge email: ' . $e->getMessage(), ['email' => $email]);
            return false;
        }
    }

    /**
     * Get email template
     */
    private static function getEmailTemplate($templateName, $variables = []) {
        $templatePath = __DIR__ . '/../templates/email/' . $templateName . '.html';

        // If template file exists, use it
        if (file_exists($templatePath)) {
            $template = file_get_contents($templatePath);
            foreach ($variables as $key => $value) {
                $template = str_replace('{{' . $key . '}}', $value, $template);
            }
            return $template;
        }

        // Otherwise, use default inline templates
        return self::getDefaultTemplate($templateName, $variables);
    }

    /**
     * Get default inline template
     */
    private static function getDefaultTemplate($templateName, $variables) {
        $baseStyles = '
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #1a0533, #2d0a5e); padding: 30px; text-align: center; }
            .header h1 { color: #66CCCC; margin: 0; }
            .content { background: #f9f9f9; padding: 30px; }
            .button { display: inline-block; background: #4a1a8f; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
            .footer { background: #eee; padding: 20px; text-align: center; font-size: 12px; color: #666; }
        ';

        switch ($templateName) {
            case 'verification':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1></div>
                        <div class='content'>
                            <h2>Welcome to GeoBeasts!</h2>
                            <p>Thank you for registering. Please verify your email address to complete your account setup.</p>
                            <p style='text-align: center;'>
                                <a href='{$variables['verify_url']}' class='button'>Verify Email Address</a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style='word-break: break-all; color: #4a1a8f;'>{$variables['verify_url']}</p>
                            <p>This link will expire in 24 hours.</p>
                        </div>
                        <div class='footer'>
                            <p>If you didn't create a GeoBeasts account, please ignore this email.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'password_reset':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1></div>
                        <div class='content'>
                            <h2>Password Reset Request</h2>
                            <p>We received a request to reset your GeoBeasts password.</p>
                            <p style='text-align: center;'>
                                <a href='{$variables['reset_url']}' class='button'>Reset Password</a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style='word-break: break-all; color: #4a1a8f;'>{$variables['reset_url']}</p>
                            <p>This link will expire in 1 hour.</p>
                        </div>
                        <div class='footer'>
                            <p>If you didn't request this password reset, please ignore this email. Your password will remain unchanged.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'friend_request':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1></div>
                        <div class='content'>
                            <h2>New Friend Request!</h2>
                            <p><strong>{$variables['from_username']}</strong> wants to be your friend on GeoBeasts.</p>
                            <p><em>How you know each other: {$variables['context']}</em></p>
                            <p style='text-align: center;'>
                                <a href='{$variables['app_url']}/friends' class='button'>View Friend Request</a>
                            </p>
                        </div>
                        <div class='footer'>
                            <p>Log in to GeoBeasts to accept or decline this friend request.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'parent_activity':
                $activityHtml = '';
                if (!empty($variables['activities'])) {
                    foreach ($variables['activities'] as $activity) {
                        $activityHtml .= "<tr><td>{$activity['type']}</td><td>{$activity['description']}</td><td>{$activity['date']}</td></tr>";
                    }
                }
                return "
                    <html><head><style>$baseStyles table { width: 100%; border-collapse: collapse; } th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; } th { background: #4a1a8f; color: white; }</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1></div>
                        <div class='content'>
                            <h2>Activity Summary for {$variables['child_username']}</h2>
                            <table>
                                <thead><tr><th>Activity</th><th>Details</th><th>Date</th></tr></thead>
                                <tbody>$activityHtml</tbody>
                            </table>
                        </div>
                        <div class='footer'>
                            <p>This is an automated summary of your child's activity on GeoBeasts.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'marketplace_offer':
                $discount = (($variables['asking_price'] - $variables['offer_price']) / $variables['asking_price']) * 100;
                $discountHtml = $variables['offer_price'] < $variables['asking_price']
                    ? "<p style='color: #ff9900; font-weight: bold;'>" . number_format($discount, 1) . "% below asking price</p>"
                    : "<p style='color: #00cc00; font-weight: bold;'>Full asking price offered!</p>";

                return "
                    <html><head><style>$baseStyles .price-box { background: #f0f0f0; padding: 15px; margin: 20px 0; border-left: 4px solid #4a1a8f; }</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1><p style='color: #C0C0C0; margin: 5px 0 0 0;'>💰 New Marketplace Offer</p></div>
                        <div class='content'>
                            <h2>Hi {$variables['seller_username']},</h2>
                            <p><strong>{$variables['buyer_username']}</strong> made an offer on your <strong>{$variables['card_name']}</strong> listing!</p>
                            <div class='price-box'>
                                <p style='margin: 5px 0;'><strong>Your Asking Price:</strong> {$variables['asking_price']} credits</p>
                                <p style='margin: 5px 0; font-size: 18px; color: #4a1a8f;'><strong>Their Offer:</strong> {$variables['offer_price']} credits</p>
                                $discountHtml
                            </div>
                            <p style='text-align: center;'>
                                <a href='{$variables['offers_url']}' class='button'>View & Respond to Offer</a>
                            </p>
                            <p style='color: #666; font-size: 14px;'>Log in to GeoBeasts to accept or reject this offer. Don't keep them waiting!</p>
                        </div>
                        <div class='footer'>
                            <p>You're receiving this because someone made an offer on your marketplace listing.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'offer_accepted':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1><p style='color: #00FF00; margin: 5px 0 0 0;'>✅ Offer Accepted!</p></div>
                        <div class='content'>
                            <h2>Great News, {$variables['buyer_username']}!</h2>
                            <p>Your offer on <strong>{$variables['card_name']}</strong> has been accepted!</p>
                            <div style='background: #e8f5e9; padding: 20px; margin: 20px 0; border-left: 4px solid #00cc00;'>
                                <p style='font-size: 18px; color: #2e7d32; margin: 0;'><strong>Offer Price: {$variables['offer_price']} credits</strong></p>
                            </div>
                            <p><strong>Next Step:</strong> Confirm your purchase to complete the transaction and add the card to your collection!</p>
                            <p style='text-align: center;'>
                                <a href='{$variables['notifications_url']}' class='button'>Confirm Purchase Now</a>
                            </p>
                            <p style='color: #666; font-size: 14px;'>The card will be added to your collection and stamped with your personal stamp once you confirm the purchase.</p>
                        </div>
                        <div class='footer'>
                            <p>Act fast! The seller is waiting for your confirmation.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'purchase_complete':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1><p style='color: #00FF00; margin: 5px 0 0 0;'>🎉 Purchase Complete!</p></div>
                        <div class='content'>
                            <h2>Congratulations, {$variables['username']}!</h2>
                            <p>You successfully purchased <strong>{$variables['card_name']}</strong>!</p>
                            <div style='background: #e3f2fd; padding: 20px; margin: 20px 0; border-left: 4px solid #2196f3;'>
                                <p style='margin: 5px 0;'>✓ Card added to your collection</p>
                                <p style='margin: 5px 0;'>✓ Stamped with your personal stamp</p>
                                <p style='margin: 5px 0;'>✓ {$variables['price']} credits deducted</p>
                            </div>
                            <p style='text-align: center;'>
                                <a href='{$variables['collection_url']}' class='button'>View Your Collection</a>
                            </p>
                            <p style='color: #666; font-size: 14px;'>Start battling with your new card today!</p>
                        </div>
                        <div class='footer'>
                            <p>Thank you for your purchase on the GeoBeasts Marketplace!</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'sale_complete':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1><p style='color: #00FF00; margin: 5px 0 0 0;'>💰 Sale Complete!</p></div>
                        <div class='content'>
                            <h2>Success, {$variables['username']}!</h2>
                            <p>You successfully sold <strong>{$variables['card_name']}</strong>!</p>
                            <div style='background: #fff8e1; padding: 20px; margin: 20px 0; border-left: 4px solid #ffc107;'>
                                <p style='font-size: 18px; color: #f57c00; margin: 0;'><strong>+{$variables['price']} credits added to your account</strong></p>
                            </div>
                            <p>The credits have been added to your balance and are ready to use!</p>
                            <p style='text-align: center;'>
                                <a href='{$variables['app_url']}/marketplace' class='button'>List More Cards</a>
                            </p>
                        </div>
                        <div class='footer'>
                            <p>Thank you for selling on the GeoBeasts Marketplace!</p>
                        </div>
                    </div>
                    </body></html>
                ";

            case 'battle_challenge':
                return "
                    <html><head><style>$baseStyles</style></head><body>
                    <div class='container'>
                        <div class='header'><h1>GeoBeasts</h1><p style='color: #FF4444; margin: 5px 0 0 0;'>⚔️ Battle Challenge!</p></div>
                        <div class='content'>
                            <h2>You've Been Challenged, {$variables['defender_username']}!</h2>
                            <p><strong>{$variables['challenger_username']}</strong> has challenged you to a <strong>{$variables['battle_type']}</strong> battle!</p>
                            <div style='background: #ffebee; padding: 20px; margin: 20px 0; border-left: 4px solid #f44336; text-align: center;'>
                                <p style='font-size: 24px; margin: 0;'>⚔️</p>
                                <p style='font-size: 18px; color: #c62828; margin: 10px 0 0 0;'><strong>{$variables['challenger_username']}</strong> VS <strong>{$variables['defender_username']}</strong></p>
                            </div>
                            <p>Will you accept the challenge? Log in now to respond!</p>
                            <p style='text-align: center;'>
                                <a href='{$variables['battles_url']}' class='button'>Accept Challenge</a>
                            </p>
                            <p style='color: #666; font-size: 14px; text-align: center;'>Prepare your deck and show them what you're made of!</p>
                        </div>
                        <div class='footer'>
                            <p>Battle notifications can be managed in your settings.</p>
                        </div>
                    </div>
                    </body></html>
                ";

            default:
                return "<html><body><p>Email content</p></body></html>";
        }
    }
}
