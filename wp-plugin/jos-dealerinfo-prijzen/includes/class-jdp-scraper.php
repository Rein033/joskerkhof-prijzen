<?php
/**
 * Haalt voertuigen (kleur + klantprijs incl. btw) op uit dealerinfo.net.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JDP_Scraper {

	const BASE = 'https://www.dealerinfo.net/';

	/** Index van de merkknop in het topmenu van dealerinfo => merknaam. */
	const BRANDS = array(
		0 => 'Peugeot',
		1 => 'SYM',
		3 => 'Yadea',
	);

	/** Categorieën zonder complete voertuigen. */
	const SKIP_CATEGORIES = array(
		'BATTERIJEN',
		'E-XPRO',
		'E-FIDDLE / E-MIO / E-XPRO NEW',
		'C1S/G5/M6(PLUS)',
		'Y1S',
		'TROOPER01',
		'OWIN/VELAX',
	);

	/** Kleuren die niet netjes uit de omschrijving te halen zijn. */
	const COLOR_OVERRIDES = array(
		'FIG4SL-M5Y2'          => 'Iced Grey',
		'J4RX25-M5PGN5477S-BK' => 'Night Purple/Black',
		'J4RX-M5PGN5477S-BK00' => 'Night Purple/Black',
		'FUG12A-M4BK502C-S352' => 'Black/Silver',
		'FUG12A-M4GY218-BK502' => 'Black/Grey',
		'ECHS12A-M6GN553U-BK4' => 'Cyan Green/Mat Black',
		'JETX12-M5BK315U'      => 'Mat Black',
		'CRU12-M4GY009C'       => 'Rock Ash',
		'ADX30-M4GY7547UL'     => 'Blue/Submarine Grey',
	);

	private $login;
	private $password;
	private $cookie_file;

	public function __construct( $login, $password ) {
		$this->login       = $login;
		$this->password    = $password;
		$this->cookie_file = wp_tempnam( 'jdp-cookies' );
	}

	public function __destruct() {
		if ( $this->cookie_file && file_exists( $this->cookie_file ) ) {
			unlink( $this->cookie_file );
		}
	}

	/**
	 * @return array[] Lijst met voertuigen: merk, categorie, artikelnummer, omschrijving, kleur, prijs, levering.
	 * @throws Exception Bij een mislukte login of verbinding.
	 */
	public function fetch() {
		$this->do_login();
		$rows = array();
		foreach ( self::BRANDS as $index => $brand ) {
			$menu = $this->select_brand( $index );
			foreach ( $this->category_links( $menu ) as $category => $href ) {
				if ( in_array( $category, self::SKIP_CATEGORIES, true ) ) {
					continue;
				}
				$html = $this->request( self::BASE . 'Pages/' . $href );
				foreach ( $this->parse_vehicles( $html ) as $row ) {
					$row['merk']      = $brand;
					$row['categorie'] = $category;
					$rows[]           = $row;
				}
			}
		}
		if ( ! $rows ) {
			throw new Exception( 'Geen voertuigen gevonden op dealerinfo.net.' );
		}
		return $rows;
	}

	private function do_login() {
		list( $html ) = $this->request( self::BASE . 'Login.aspx', null, true );
		$data = self::hidden_fields( $html );
		$data['txtLogin']      = $this->login;
		$data['txtPassword']   = $this->password;
		$data['btnLogin']      = 'OK';
		$data['lstLanguages']  = 'NL';
		list( , $url ) = $this->request( self::BASE . 'Login.aspx', $data, true );
		if ( false !== stripos( $url, 'Login.aspx' ) ) {
			throw new Exception( 'Inloggen op dealerinfo.net mislukt. Controleer login en wachtwoord.' );
		}
	}

	private function select_brand( $index ) {
		list( $html, $url ) = $this->request( self::BASE . 'Pages/NewsSelection.aspx', null, true );
		$data   = self::hidden_fields( $html );
		$button = sprintf( 'ctl00$ctl00$topMenu$rptBrands$ctl%02d$btnBrand', $index );
		$data[ $button . '.x' ] = '10';
		$data[ $button . '.y' ] = '10';
		return $this->request( $url, $data );
	}

	private function category_links( $html ) {
		$links = array();
		$xpath = self::xpath( $html );
		foreach ( $xpath->query( '//a[contains(@href,"CategoryDetail.aspx") and contains(@href,"catalog=1&")]' ) as $a ) {
			$name = trim( preg_replace( '/\s+/', ' ', $a->textContent ) );
			if ( '' !== $name ) {
				$links[ $name ] = html_entity_decode( $a->getAttribute( 'href' ) );
			}
		}
		return $links;
	}

	private function parse_vehicles( $html ) {
		$rows  = array();
		$xpath = self::xpath( $html );
		foreach ( $xpath->query( '//tr[contains(@class,"clickable") and contains(@onclick,"PartDetail")]' ) as $tr ) {
			$art   = self::text( $xpath, './/*[contains(@id,"lblArtNr_")]', $tr );
			$desc  = self::text( $xpath, './/*[contains(@id,"lblDescription_")]', $tr );
			$avail = self::text( $xpath, './/*[contains(@id,"lblAvailability_")]', $tr );
			$price = self::text( $xpath, './/td[contains(@class,"align-right")]', $tr );
			if ( '' === $art || '' === $price ) {
				continue;
			}
			$rows[] = array(
				'artikelnummer' => $art,
				'omschrijving'  => $desc,
				'kleur'         => self::color_of( $art, $desc ),
				'prijs'         => self::parse_price( $price ),
				'levering'      => $avail,
			);
		}
		return $rows;
	}

	public static function color_of( $art, $desc ) {
		if ( isset( self::COLOR_OVERRIDES[ $art ] ) ) {
			return self::COLOR_OVERRIDES[ $art ];
		}
		$c = trim( preg_replace( '/\s*\([A-Z0-9-]+\)$/', '', $desc ) );
		$c = preg_replace( '#.*?(E5\+|E5P|M\d|KM/H|KM|45|EVO|TCS|KEYLS|KEYLESS|1BATT M\d|CC|ABS)\s+(?=[A-Z/.]+( [A-Z/.]+)*$)#', '', $c );
		$c = ucwords( strtolower( $c ), " /\t" );
		if ( preg_match( '/BLACK EDITION|SHADOW|SPORT/', $desc, $m ) ) {
			$c .= ' (' . ucwords( strtolower( $m[0] ) ) . ')';
		}
		return $c;
	}

	public static function parse_price( $text ) {
		$n = preg_replace( '/[^\d,]/', '', $text );
		return (float) str_replace( ',', '.', $n );
	}

	private static function hidden_fields( $html ) {
		$data  = array();
		$xpath = self::xpath( $html );
		foreach ( $xpath->query( '//input[@type="hidden" and @name]' ) as $input ) {
			$data[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
		}
		return $data;
	}

	private static function xpath( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		return new DOMXPath( $doc );
	}

	private static function text( DOMXPath $xpath, $query, $context ) {
		$node = $xpath->query( $query, $context )->item( 0 );
		return $node ? trim( preg_replace( '/\s+/u', ' ', str_replace( "\xc2\xa0", ' ', $node->textContent ) ) ) : '';
	}

	/**
	 * cURL met cookiebestand: de ASP.NET-sessie moet over redirects heen bewaard blijven.
	 *
	 * @return string|array Body, of [body, effectieve URL] als $with_url.
	 */
	private function request( $url, $post = null, $with_url = false ) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_COOKIEFILE     => $this->cookie_file,
				CURLOPT_COOKIEJAR      => $this->cookie_file,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (Jos Kerkhof prijzen-sync)',
			)
		);
		if ( null !== $post ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $post ) );
		}
		$body = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$eff  = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
		$err  = curl_error( $ch );
		curl_close( $ch );
		if ( false === $body || $code >= 400 ) {
			throw new Exception( sprintf( 'dealerinfo.net gaf een fout (%s): %s', $code, $err ) );
		}
		return $with_url ? array( $body, $eff ) : $body;
	}
}
