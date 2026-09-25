<?php
/**
 * Zet de dealerinfo-voertuigen om naar producten van het thema jos-tweewielers.
 *
 * Per model één product (25 en 45 km/u samen), per kleur een foto en prijs.
 * Thema-velden: jos_prijs, jos_prijs_vanaf, jos_levering, jos_kleuren (JSON [{naam,id,prijs}]).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JDP_Sync {

	const STATE_OPTION = 'jdp_sync_state';
	const LOG_OPTION   = 'jdp_sync_log';
	const TIME_BUDGET  = 20; // seconden per verzoek, daarna gaat de sync verder in een volgende stap

	/** Dealerinfo-categorie => modelnaam op de site. */
	const MODEL_NAMES = array(
		'KISBEE S 25 KM/H E5+'          => 'Kisbee',
		'KISBEE 45 KM/H E5+'            => 'Kisbee',
		'KISBEE S SPECIAL 25 KM/H E5+'  => 'Kisbee Specials',
		'KISBEE SPECIALS 45 KM/H E5+'   => 'Kisbee Specials',
		'SPEEDFIGHT 4 45 KM/H E5+'      => 'Speedfight 4',
		'FIDDLE II 25 KM/H E5+'         => 'Fiddle II',
		'FIDDLE II 45 KM/H E5+'         => 'Fiddle II',
		'FIDDLE IV 25 KM/H E5+'         => 'Fiddle IV',
		'FIDDLE IV 45 KM/H E5+'         => 'Fiddle IV',
		'JET 14 25 KM/H E5+'            => 'Jet 14',
		'JET 14 45 KM/H E5+'            => 'Jet 14',
		'ORBIT III 25 KM/H E5+'         => 'Orbit III',
		'ORBIT III 45 KM/H E5+'         => 'Orbit III',
		'SYMPHONY 25 KM/H E5+'          => 'Symphony',
		'SYMPHONY 45 KM/H E5+'          => 'Symphony',
		'XPRO 25 KM/H E5+'              => 'X-Pro',
		'XPRO 45 KM/H E5+'              => 'X-Pro',
		'J4RX 25 KM/H E5+'              => 'Jet 4 RX',
		'J4RX 45 KM/H E5+'              => 'Jet 4 RX',
		'MIO 50I 45 KM/H E5+'           => 'Mio 50i',
		'FUGUE 125 AC ABS E5+'          => 'Fugue 125',
		'ECHS 125 ABS+TCS'              => 'ECHS 125',
		'JET 14 125 AC E5+ EVO'         => 'Jet 14 125 EVO',
		'JET X 125 E5'                  => 'Jet X 125',
		'ADX 125 E5+'                   => 'ADX 125',
		'CRUISYM ALPHA 125 E5'          => 'Cruisym Alpha 125',
		'ADX 300 E5+'                   => 'ADX 300',
		'CRUISYM ALPHA 300 E5+'         => 'Cruisym Alpha 300',
		'CRUISYM 400 E5+'               => 'Cruisym 400',
		'ADXTG 400 E5+'                 => 'ADX TG 400',
		'MAXSYM TL508 E5+'              => 'Maxsym TL 508',
		'TTL BT 508'                    => 'TTL BT 508',
		'OWIN 25 KM/H'                  => 'Owin',
		'OWIN 45 KM/H'                  => 'Owin',
		'E8S 25 KM/H'                   => 'E8S Lite',
		'E10 45 KM/H'                   => 'E10',
		'VOLTGUARD 45 KM/H'             => 'Voltguard',
		'Y1SH 45 KM/H'                  => 'Y1S H',
		'VELAX 45 KM/H'                 => 'Velax',
		'M6L 45 KM/H'                   => 'M6L',
	);

	/**
	 * Start een nieuwe sync: dealerinfo ophalen en de modellen klaarzetten.
	 */
	public static function start() {
		$settings = JDP_Plugin::settings();
		if ( ! $settings['login'] || ! $settings['password'] ) {
			throw new Exception( 'Vul eerst de dealerinfo login en wachtwoord in.' );
		}
		$scraper = new JDP_Scraper( $settings['login'], $settings['password'] );
		$groups  = self::group( $scraper->fetch() );
		update_option(
			self::STATE_OPTION,
			array(
				'started' => time(),
				'groups'  => $groups,
				'done'     => array(),
				'log'      => array(),
				'attempts' => array(),
			),
			false
		);
	}

	public static function state() {
		return get_option( self::STATE_OPTION, null );
	}

	public static function is_running() {
		$state = self::state();
		return is_array( $state ) && ! empty( $state['groups'] );
	}

	/**
	 * Verwerkt modellen tot het tijdsbudget op is.
	 *
	 * @return bool True als alles klaar is.
	 */
	public static function step() {
		$state = self::state();
		if ( ! is_array( $state ) ) {
			return true;
		}
		$deadline = time() + self::TIME_BUDGET;
		while ( $state['groups'] && time() < $deadline ) {
			$key   = array_key_first( $state['groups'] );
			$group = $state['groups'][ $key ];
			// Liep een vorige poging vast (bijv. tijdslimiet bij een enorme foto)? Dan kleine foto's gebruiken.
			$attempt = isset( $state['attempts'][ $key ] ) ? $state['attempts'][ $key ] + 1 : 1;
			$state['attempts'][ $key ] = $attempt;
			update_option( self::STATE_OPTION, $state, false );
			try {
				$state['log'][] = self::sync_group( $key, $group, $attempt > 1 );
			} catch ( Exception $e ) {
				$state['log'][] = array( 'model' => $group['title'], 'actie' => 'fout', 'details' => $e->getMessage() );
			}
			$state['done'][] = $key;
			unset( $state['groups'][ $key ] );
			update_option( self::STATE_OPTION, $state, false );
		}
		if ( $state['groups'] ) {
			return false;
		}
		$state['log'] = array_merge( $state['log'], self::retire_missing( $state['done'] ) );
		update_option(
			self::LOG_OPTION,
			array(
				'finished' => time(),
				'log'      => $state['log'],
			),
			false
		);
		delete_option( self::STATE_OPTION );
		return true;
	}

	/**
	 * Groepeert dealerinfo-regels per model en voegt 25/45 km/u-kleuren samen.
	 */
	public static function group( array $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$model = isset( self::MODEL_NAMES[ $row['categorie'] ] )
				? self::MODEL_NAMES[ $row['categorie'] ]
				: ucwords( strtolower( trim( preg_replace( '#\b(25|45) ?KM/H\b|\bE5\+?#', '', $row['categorie'] ) ) ) );
			$key = sanitize_title( $row['merk'] . ' ' . $model );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'title'  => $row['merk'] . ' ' . $model,
					'merk'   => $row['merk'],
					'colors' => array(),
				);
			}
			$speed     = preg_match( '#\b(25|45) ?KM/H#', $row['categorie'], $m ) ? $m[1] : '';
			$color_key = self::color_key( $row['artikelnummer'] );
			$colors    = &$groups[ $key ]['colors'];
			if ( ! isset( $colors[ $color_key ] ) ) {
				$colors[ $color_key ] = array(
					'naam'     => $row['kleur'],
					'prijs'    => $row['prijs'],
					'speeds'   => array(),
					'arts'     => array(),
					'levering' => array(),
				);
			}
			$colors[ $color_key ]['prijs']      = max( $colors[ $color_key ]['prijs'], $row['prijs'] );
			$colors[ $color_key ]['arts'][]     = $row['artikelnummer'];
			$colors[ $color_key ]['levering'][] = $row['levering'];
			if ( $speed ) {
				$colors[ $color_key ]['speeds'][] = $speed;
			}
			unset( $colors );
		}
		return $groups;
	}

	/**
	 * Zelfde kleur in 25 en 45 km/u: "FI25-M5BK007U" en "FI-M5BK007U" worden "FI-BK007U",
	 * "OWIN25-M5-001" en "OWIN-M5-001" worden "OWIN-001".
	 */
	public static function color_key( $art ) {
		$parts = explode( '-', $art );
		$model = preg_replace( '/25$/', '', array_shift( $parts ) );
		if ( $parts && preg_match( '/^M\d$/', $parts[0] ) ) {
			array_shift( $parts );
		}
		$color = $parts ? preg_replace( '/^M\d/', '', $parts[0] ) : '';
		return $model . '-' . $color;
	}

	private static function sync_group( $key, array $group, $small_photos = false ) {
		$settings = JDP_Plugin::settings();
		$extra    = (float) $settings['rijklaar'];
		$post_id  = self::find_product( $key );
		$created  = ! $post_id;

		$speeds = array();
		foreach ( $group['colors'] as $c ) {
			$speeds = array_merge( $speeds, $c['speeds'] );
		}
		$speeds = array_values( array_unique( $speeds ) );
		sort( $speeds );

		if ( $created ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'product',
					'post_status'  => $settings['status'],
					'post_title'   => $group['title'],
					'post_content' => self::default_content( $group, $speeds ),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				throw new Exception( $post_id->get_error_message() );
			}
			update_post_meta( $post_id, '_jdp_key', $key );
			$term = term_exists( 'Scooters', 'product_categorie' );
			if ( $term ) {
				wp_set_object_terms( $post_id, (int) $term['term_id'], 'product_categorie' );
			}
		} elseif ( 'publish' !== get_post_status( $post_id ) && get_post_meta( $post_id, '_jdp_retired', true ) ) {
			// Staat weer bij dealerinfo: opnieuw online zetten.
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
			delete_post_meta( $post_id, '_jdp_retired' );
		}

		$prices = array();
		$kleuren = array();
		$missing_photo = array();
		foreach ( $group['colors'] as $c ) {
			$price    = $c['prijs'] + $extra;
			$prices[] = $price;
			$image_id = self::image_for( $c['arts'], $post_id, $small_photos );
			if ( ! $image_id ) {
				$missing_photo[] = $c['naam'];
			}
			$kleuren[] = array(
				'naam'  => $c['naam'],
				'id'    => $image_id,
				'prijs' => self::format_price( $price ),
			);
		}
		$min   = min( $prices );
		$vanaf = count( array_unique( $prices ) ) > 1;
		// Zelfde prijs voor alle kleuren: prijs per kleur leeg laten, dan toont het thema de hoofdprijs.
		if ( ! $vanaf ) {
			foreach ( $kleuren as &$k ) {
				unset( $k['prijs'] );
			}
			unset( $k );
		}
		$kleuren = array_values( array_filter( $kleuren, function ( $k ) {
			return $k['id'];
		} ) );

		update_post_meta( $post_id, 'jos_prijs', self::format_price( $min ) );
		update_post_meta( $post_id, 'jos_prijs_vanaf', $vanaf ? '1' : '' );
		update_post_meta( $post_id, 'jos_kleuren', wp_json_encode( $kleuren ) );
		update_post_meta( $post_id, 'jos_levering', self::delivery_text( $group ) );
		update_post_meta( $post_id, '_jdp_synced', time() );
		if ( $kleuren && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $kleuren[0]['id'] );
		}

		$details = sprintf( '%s, %d kleur(en)', ( $vanaf ? 'vanaf ' : '' ) . self::format_price( $min ), count( $group['colors'] ) );
		if ( $missing_photo ) {
			$details .= '. Geen foto: ' . implode( ', ', $missing_photo );
		}
		return array(
			'model'   => $group['title'],
			'post_id' => $post_id,
			'actie'   => $created ? 'aangemaakt' : 'bijgewerkt',
			'details' => $details,
		);
	}

	/**
	 * Producten die door deze plugin zijn aangemaakt maar niet meer bij dealerinfo staan: naar concept.
	 */
	private static function retire_missing( array $seen_keys ) {
		$log   = array();
		$posts = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'numberposts' => -1,
				'meta_key'    => '_jdp_key',
				'fields'      => 'ids',
			)
		);
		foreach ( $posts as $post_id ) {
			if ( in_array( get_post_meta( $post_id, '_jdp_key', true ), $seen_keys, true ) ) {
				continue;
			}
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
			update_post_meta( $post_id, '_jdp_retired', 1 );
			$log[] = array(
				'model'   => get_the_title( $post_id ),
				'post_id' => $post_id,
				'actie'   => 'naar concept',
				'details' => 'Niet meer in de dealerinfo-catalogus',
			);
		}
		return $log;
	}

	private static function find_product( $key ) {
		$ids = get_posts(
			array(
				'post_type'   => 'product',
				'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
				'numberposts' => 1,
				'meta_key'    => '_jdp_key',
				'meta_value'  => $key,
				'fields'      => 'ids',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Foto van dealerinfo in de mediabibliotheek (hergebruikt als hij er al is).
	 * Probeert eerst de grote foto per artikel, daarna de thumbnail.
	 */
	private static function image_for( array $arts, $post_id, $small_only = false ) {
		foreach ( $arts as $art ) {
			$existing = get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'numberposts' => 1,
					'meta_key'    => '_jdp_art',
					'meta_value'  => $art,
					'fields'      => 'ids',
				)
			);
			if ( $existing ) {
				return (int) $existing[0];
			}
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$candidates = array();
		foreach ( $small_only ? array() : $arts as $art ) {
			$candidates[] = array( $art, JDP_Scraper::BASE . 'pictures/' . rawurlencode( $art ) . '-1.jpg' );
			$candidates[] = array( $art, JDP_Scraper::BASE . 'pictures/' . rawurlencode( $art ) . '.jpg' );
		}
		foreach ( $arts as $art ) {
			$candidates[] = array( $art, JDP_Scraper::BASE . 'pictures/thumbs/th_160_160_' . rawurlencode( $art ) . '-1.jpg' );
		}
		foreach ( $candidates as list( $art, $url ) ) {
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				continue;
			}
			$file = array(
				'name'     => sanitize_file_name( strtolower( $art ) ) . '.jpg',
				'tmp_name' => $tmp,
			);
			$id = media_handle_sideload( $file, $post_id, get_the_title( $post_id ) );
			if ( is_wp_error( $id ) ) {
				if ( file_exists( $tmp ) ) {
					unlink( $tmp );
				}
				continue;
			}
			update_post_meta( $id, '_jdp_art', $art );
			return (int) $id;
		}
		return 0;
	}

	private static function delivery_text( array $group ) {
		$all = array();
		foreach ( $group['colors'] as $c ) {
			$all = array_merge( $all, $c['levering'] );
		}
		if ( in_array( 'Stock', $all, true ) || in_array( 'Beperkte stock', $all, true ) ) {
			return 'Direct leverbaar';
		}
		$weeks = array();
		foreach ( $all as $l ) {
			if ( preg_match( '/Week (\d+)/', $l, $m ) ) {
				$weeks[] = (int) $m[1];
			}
		}
		return $weeks ? 'Week ' . min( $weeks ) : '';
	}

	private static function default_content( array $group, array $speeds ) {
		$names = array();
		foreach ( $group['colors'] as $c ) {
			$names[] = $c['naam'];
		}
		$lines = array();
		if ( array( '25', '45' ) === $speeds ) {
			$lines[] = 'Leverbaar als snorfiets (25 km/u, blauw kenteken) en als bromfiets (45 km/u, geel kenteken).';
		} elseif ( array( '25' ) === $speeds ) {
			$lines[] = 'Leverbaar als snorfiets (25 km/u, blauw kenteken).';
		} elseif ( array( '45' ) === $speeds ) {
			$lines[] = 'Leverbaar als bromfiets (45 km/u, geel kenteken).';
		}
		$lines[] = 'Verkrijgbaar in: ' . implode( ', ', $names ) . '.';
		$lines[] = 'Prijs inclusief btw, rijklaar maken en kenteken.';
		return '<p>' . implode( '</p><p>', array_map( 'esc_html', $lines ) ) . '</p>';
	}

	public static function format_price( $amount ) {
		return '€ ' . number_format( $amount, 2, ',', '.' );
	}
}
