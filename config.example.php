<?php
/**
 * Copy this file to config.php on the server and fill in the real values.
 *
 * config.php is git-ignored on purpose — the bot token must never be
 * committed or served to the browser. Anyone holding it controls the bot.
 */

return [
    // From @BotFather. Keep it secret.
    'bot_token' => 'PASTE_BOT_TOKEN_HERE',

    // Where submissions are delivered. A group id is negative,
    // e.g. -1001234567890. A private chat id is positive.
    'chat_id' => 'PASTE_CHAT_ID_HERE',

    // Optional: mirror every lead to this mailbox as well. Empty = off.
    'notify_email' => '',

    // Max submissions accepted from one IP per hour.
    'rate_limit_per_hour' => 5,
];
