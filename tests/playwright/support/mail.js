// Reads mail the local app sent, so specs can follow emailed links (budget
// invitations store only a hash of their token, so the link exists only in the
// email).
//
// Locally the app sends mail over SMTP to Herd's mail server (MAIL_PORT=2525),
// which stores each message in Herd's Core Data store. We read that store
// read-only with the sqlite3 CLI. If MAIL_MAILER=log is used instead, the
// message is found in storage/logs/laravel.log.
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const HERD_MAIL_DB = join(homedir(), 'Library/Application Support/Herd/HerdCoreData.sqlite');
const LARAVEL_LOG = join(process.cwd(), 'storage/logs/laravel.log');

function latestHerdMailTo(email) {
    if (!existsSync(HERD_MAIL_DB)) {
        return null;
    }
    const sql =
        "SELECT COALESCE(m.ZHTML, '') || ' ' || COALESCE(m.ZTEXT, '') FROM ZCDMAIL m " +
        "JOIN ZCDMAILADDRESS a ON a.ZMAIL = m.Z_PK AND a.ZTYPE = 'to' " +
        `WHERE lower(a.ZADDRESS) = lower('${email.replace(/'/g, "''")}') ORDER BY m.Z_PK DESC LIMIT 1;`;
    try {
        return execFileSync('sqlite3', ['-readonly', HERD_MAIL_DB, sql], { encoding: 'utf8' }) || null;
    } catch {
        return null;
    }
}

function latestLoggedMailTo(email) {
    if (!existsSync(LARAVEL_LOG)) {
        return null;
    }
    const log = readFileSync(LARAVEL_LOG, 'utf8');
    const index = log.lastIndexOf(`To: ${email}`);
    return index === -1 ? null : log.slice(index, index + 20_000);
}

/**
 * Wait for an email to `email` containing a link that matches `linkPattern`
 * and return the first match (HTML entities decoded).
 */
export async function waitForMailedLink(email, linkPattern, { timeout = 15_000 } = {}) {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
        for (const body of [latestHerdMailTo(email), latestLoggedMailTo(email)]) {
            const match = body?.replace(/&amp;/g, '&').match(linkPattern);
            if (match) {
                return match[0];
            }
        }
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    throw new Error(
        `No email to ${email} with a link matching ${linkPattern} within ${timeout}ms. ` +
            'These specs read mail from Herd\'s local mail server (MAIL_MAILER=smtp, port 2525) or, with MAIL_MAILER=log, from storage/logs/laravel.log.',
    );
}
