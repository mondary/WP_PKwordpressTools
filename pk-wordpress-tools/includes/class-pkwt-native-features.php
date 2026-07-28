<?php
/**
 * Native feature registry.
 *
 * @package PK_WordPress_Tools
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

class PKWT_Native_Features {

	private const OPTION_KEY = 'pkwt_native_features';

	/** @return array<string, array<string, string> > */
	public static function definitions(): array {
		return [
			'editorial-calendar'       => [ 'name' => __( 'Calendrier éditorial', 'pk-wordpress-tools' ), 'description' => __( 'Page Articles > Calendrier, glisser-déposer et réallocation des articles.', 'pk-wordpress-tools' ), 'category' => __( 'Planification', 'pk-wordpress-tools' ) ],
			'editor-next-free-slot'    => [ 'name' => __( 'Prochain créneau libre dans Gutenberg', 'pk-wordpress-tools' ), 'description' => __( 'Suggère le prochain créneau disponible dans l’éditeur Gutenberg.', 'pk-wordpress-tools' ), 'category' => __( 'Planification', 'pk-wordpress-tools' ) ],
			'news-player'              => [ 'name' => __( 'Lecteur d’articles plein écran', 'pk-wordpress-tools' ), 'description' => __( 'Ouvre les derniers articles dans un lecteur plein écran côté site.', 'pk-wordpress-tools' ), 'category' => __( 'Site public', 'pk-wordpress-tools' ) ],
			'missing-featured-images'  => [ 'name' => __( 'Détection des articles sans image mise en avant', 'pk-wordpress-tools' ), 'description' => __( 'Ajoute la vue des articles publiés sans image mise en avant.', 'pk-wordpress-tools' ), 'category' => __( 'Médias', 'pk-wordpress-tools' ) ],
			'featured-image-column'    => [ 'name' => __( 'Colonne image mise en avant et suppression du média', 'pk-wordpress-tools' ), 'description' => __( 'Ajoute la colonne image aux Articles et sa suppression protégée.', 'pk-wordpress-tools' ), 'category' => __( 'Médias', 'pk-wordpress-tools' ) ],
			'admin-bar-search'         => [ 'name' => __( 'Recherche dans la barre d’administration', 'pk-wordpress-tools' ), 'description' => __( 'Ajoute une recherche d’articles dans la barre d’administration.', 'pk-wordpress-tools' ), 'category' => __( 'Administration', 'pk-wordpress-tools' ) ],
			'active-plugins-first'     => [ 'name' => __( 'Extensions actives en premier', 'pk-wordpress-tools' ), 'description' => __( 'Trie les extensions actives avant les autres sur leur écran WordPress.', 'pk-wordpress-tools' ), 'category' => __( 'Administration', 'pk-wordpress-tools' ) ],
			'post-search-auto'         => [ 'name' => __( 'Recherche instantanée côté site', 'pk-wordpress-tools' ), 'description' => __( 'Active le raccourci de recherche côté site lorsqu’un formulaire compatible est présent.', 'pk-wordpress-tools' ), 'category' => __( 'Site public', 'pk-wordpress-tools' ) ],
			'reading-progress'         => [ 'name' => __( 'Barre de progression de lecture', 'pk-wordpress-tools' ), 'description' => __( 'Affiche la progression de lecture sur les articles individuels.', 'pk-wordpress-tools' ), 'category' => __( 'Site public', 'pk-wordpress-tools' ) ],
			'markdown-rag-export'      => [ 'name' => __( 'Export Markdown pour RAG', 'pk-wordpress-tools' ), 'description' => __( 'Exporte les articles au format Markdown dans une archive ZIP.', 'pk-wordpress-tools' ), 'category' => __( 'Export', 'pk-wordpress-tools' ) ],
		];
	}

	public static function is_enabled( string $feature ): bool {
		if ( ! isset( self::definitions()[ $feature ] ) ) {
			return false;
		}

		$features = self::get_states();
		return (bool) $features[ $feature ];
	}

	public static function set_enabled( string $feature, bool $enabled ): bool {
		if ( ! isset( self::definitions()[ $feature ] ) ) {
			return false;
		}

		$features = self::get_states();
		$features[ $feature ] = $enabled;
		update_option( self::OPTION_KEY, $features );
		return true;
	}

	/** @return array<string, bool> */
	private static function get_states(): array {
		$defaults = array_fill_keys( array_keys( self::definitions() ), true );
		$stored   = get_option( self::OPTION_KEY, null );
		if ( ! is_array( $stored ) ) {
			update_option( self::OPTION_KEY, $defaults );
			return $defaults;
		}

		$states = array_intersect_key( $stored, $defaults );
		$states = array_merge( $defaults, array_map( 'boolval', $states ) );
		if ( $states !== $stored ) {
			update_option( self::OPTION_KEY, $states );
		}
		return $states;
	}
}
