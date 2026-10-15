<?php
declare(strict_types=1);

namespace RivianTrackr\Passkeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Throwaway email providers that are refused at signup. The built-in list
 * covers the services that show up most in spam registrations; the site
 * owner can add more from Settings → Passkeys.
 */
final class DisposableDomains {

	private const BUILT_IN = [
		'10minutemail.com',
		'10minutemail.net',
		'10minemail.com',
		'20minutemail.com',
		'anonbox.net',
		'armyspy.com',
		'binkmail.com',
		'bobmail.info',
		'burnermail.io',
		'byom.de',
		'chacuo.net',
		'crazymailing.com',
		'cuvox.de',
		'dayrep.com',
		'deadaddress.com',
		'despam.it',
		'discard.email',
		'discardmail.com',
		'dispostable.com',
		'dodgit.com',
		'dropmail.me',
		'e4ward.com',
		'einrot.com',
		'emailondeck.com',
		'emailfake.com',
		'emailtemporanea.com',
		'emltmp.com',
		'ethereal.email',
		'fakeinbox.com',
		'fakemail.net',
		'fakemailgenerator.com',
		'filzmail.com',
		'fleckens.hu',
		'getairmail.com',
		'getnada.com',
		'gishpuppy.com',
		'grr.la',
		'guerrillamail.biz',
		'guerrillamail.com',
		'guerrillamail.de',
		'guerrillamail.info',
		'guerrillamail.net',
		'guerrillamail.org',
		'guerrillamailblock.com',
		'gustr.com',
		'harakirimail.com',
		'hidemail.de',
		'hmamail.com',
		'inboxbear.com',
		'inboxkitten.com',
		'incognitomail.com',
		'jetable.org',
		'jourrapide.com',
		'kasmail.com',
		'koszmail.pl',
		'kurzepost.de',
		'linshiyouxiang.net',
		'luxusmail.org',
		'mail-temp.com',
		'mail.tm',
		'mail7.io',
		'mailcatch.com',
		'maildrop.cc',
		'maildu.de',
		'maileater.com',
		'mailexpire.com',
		'mailforspam.com',
		'mailhazard.com',
		'mailimate.com',
		'mailin8r.com',
		'mailinater.com',
		'mailinator.com',
		'mailinator.net',
		'mailinator2.com',
		'mailmetrash.com',
		'mailmoat.com',
		'mailnesia.com',
		'mailnull.com',
		'mailpoof.com',
		'mailsac.com',
		'mailscrap.com',
		'mailslite.com',
		'mailtemp.info',
		'mailtothis.com',
		'mailzilla.com',
		'mega.zik.dj',
		'meltmail.com',
		'mintemail.com',
		'moakt.com',
		'mohmal.com',
		'mytemp.email',
		'mytrashmail.com',
		'nada.email',
		'netmails.net',
		'nomail.xl.cx',
		'nospam.ze.tc',
		'nowmymail.com',
		'objectmail.com',
		'onewaymail.com',
		'owlpic.com',
		'pokemail.net',
		'proxymail.eu',
		'rcpt.at',
		'reallymymail.com',
		'rhyta.com',
		'rtrtr.com',
		'safetymail.info',
		'sharklasers.com',
		'shieldemail.com',
		'sogetthis.com',
		'spam4.me',
		'spamavert.com',
		'spambog.com',
		'spambox.us',
		'spamex.com',
		'spamfree24.org',
		'spamgourmet.com',
		'spamherelots.com',
		'spamhole.com',
		'spaml.com',
		'spamspot.com',
		'superrito.com',
		'teleworm.us',
		'temp-mail.io',
		'temp-mail.org',
		'tempail.com',
		'tempemail.com',
		'tempemail.net',
		'tempinbox.com',
		'tempmail.com',
		'tempmail.de',
		'tempmail.net',
		'tempmail.plus',
		'tempmailaddress.com',
		'tempmailo.com',
		'tempomail.fr',
		'temporaryemail.net',
		'temporaryinbox.com',
		'tempr.email',
		'tempsky.com',
		'thankyou2010.com',
		'thisisnotmyrealemail.com',
		'throwam.com',
		'throwawaymail.com',
		'tmail.ws',
		'tmailinator.com',
		'tmpmail.net',
		'tmpmail.org',
		'trash-mail.com',
		'trash2009.com',
		'trashmail.at',
		'trashmail.com',
		'trashmail.de',
		'trashmail.io',
		'trashmail.me',
		'trashmail.net',
		'trashymail.com',
		'tyldd.com',
		'wegwerfmail.de',
		'wegwerfmail.net',
		'wh4f.org',
		'yopmail.com',
		'yopmail.fr',
		'yopmail.net',
		'zoemail.net',
	];

	/**
	 * Domain part of an email address, lowercased, or "" when malformed.
	 */
	public static function domain_of( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return '';
		}
		return strtolower( trim( substr( $email, $at + 1 ), " \t\r\n." ) );
	}

	/**
	 * Parse an admin-entered list (one per line, or comma/space separated).
	 *
	 * @return string[] Lowercased domains without a leading "@" or dot.
	 */
	public static function parse_list( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/[\r\n,;\s]+/', $raw ) ?: [] as $line ) {
			$domain = strtolower( trim( $line, " \t@." ) );
			if ( '' !== $domain && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) {
				$out[] = $domain;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether the address belongs to a disposable provider. Subdomains of a
	 * listed domain count too (e.g. anything.mailinator.com).
	 *
	 * @param string   $email Address to check.
	 * @param string[] $extra Additional blocked domains.
	 */
	public static function is_disposable( string $email, array $extra = [] ): bool {
		$domain = self::domain_of( $email );
		if ( '' === $domain ) {
			return false;
		}

		$blocked = array_fill_keys( self::BUILT_IN, true );
		foreach ( $extra as $item ) {
			$item = strtolower( trim( (string) $item, " \t@." ) );
			if ( '' !== $item ) {
				$blocked[ $item ] = true;
			}
		}

		/**
		 * Filter the full set of blocked signup domains.
		 *
		 * @param string[] $domains Lowercased domains.
		 * @param string   $email   Address being checked.
		 */
		$domains = (array) apply_filters( 'riviantrackr_passkeys_blocked_domains', array_keys( $blocked ), $email );
		$blocked = array_fill_keys( array_map( 'strval', $domains ), true );

		$labels = explode( '.', $domain );
		$depth  = count( $labels );
		for ( $i = 0; $i <= $depth - 2; $i++ ) {
			if ( isset( $blocked[ implode( '.', array_slice( $labels, $i ) ) ] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function built_in_count(): int {
		return count( self::BUILT_IN );
	}
}
