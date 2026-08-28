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

    // Where submissions are delivered — the "Hmara Build Zayavky" group.
    // A group id is negative; a private chat id is positive. Harmless on
    // its own: without the token nobody can post to it.
    'chat_id' => '-5347964265',

    // Optional: mirror every lead to this mailbox as well. Empty = off.
    'notify_email' => '',

    // Max submissions accepted from one IP per hour.
    'rate_limit_per_hour' => 5,
];
