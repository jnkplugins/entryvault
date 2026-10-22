<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * Human names for the `source_plugin` values stored on submissions.
 *
 * The Submissions list, its filter, the Sources report and the detail panel all
 * printed the raw slug ("contact-form-7", "csv_import") while the Health screen
 * said "Contact Form 7". Every screen now asks this class instead.
 */
class Sources {

    /** @var array<string,string> */
    private const LABELS = [
        'contact-form-7' => 'Contact Form 7',
        'elementor-forms' => 'Elementor Forms',
        'everest-forms'  => 'Everest Forms',
        'fluent-forms'   => 'Fluent Forms',
        'formidable'     => 'Formidable Forms',
        'forminator'     => 'Forminator',
        'gravity-forms'  => 'Gravity Forms',
        'jetpack-forms'  => 'Jetpack Forms',
        'kadence-forms'  => 'Kadence Forms',
        'metform'        => 'MetForm',
        'ninja-forms'    => 'Ninja Forms',
        'otter-forms'    => 'Otter Forms',
        'spectra-forms'  => 'Spectra Forms',
        'sureforms'      => 'SureForms',
        'trinity-forms'  => 'Trinity Forms',
        'wpforms'        => 'WPForms',
        'ws-form'        => 'WS Form',
        'csv_import'     => 'CSV import',
    ];

    /**
     * Label for one stored source value. Unknown values are humanised
     * ("my_source" → "My Source") rather than printed raw.
     */
    public static function label( string $slug ): string {
        $slug = trim( $slug );
        if ( '' === $slug ) {
            return '';
        }
        $label = self::LABELS[ $slug ] ?? ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );

        /**
         * Filter the display name of a submission source.
         *
         * @param string $label Display name.
         * @param string $slug  Stored source_plugin value.
         */
        return (string) apply_filters( 'entryvault_source_label', $label, $slug );
    }

    /**
     * The form's own name, for "Trinity Forms / Project quote" instead of
     * "form 7". Only for plugins whose form ID is unambiguous: those that store
     * forms as their own post type, and Gravity Forms through its API. Anything
     * else returns '' and the caller keeps showing the number.
     */
    public static function form_title( string $slug, int $form_id ): string {
        if ( $form_id <= 0 ) {
            return '';
        }
        $post_types = [
            'trinity-forms'  => 'trinity_forms_form',
            'contact-form-7' => 'wpcf7_contact_form',
            'wpforms'        => 'wpforms',
            'everest-forms'  => 'everest_form',
            'sureforms'      => 'sureforms_form',
            'metform'        => 'metform-form',
            // Only Kadence's Advanced Form has a numeric id (a kadence_form post);
            // the simple block's id is not a number and never gets here as one.
            'kadence-forms'  => 'kadence_form',
        ];
        if ( isset( $post_types[ $slug ] ) && function_exists( 'get_post' ) ) {
            $post = get_post( $form_id );
            return ( $post && $post->post_type === $post_types[ $slug ] ) ? (string) $post->post_title : '';
        }
        if ( 'ws-form' === $slug && class_exists( '\\WS_Form_Form' ) ) {
            global $wpdb;
            // WS Form keeps forms in its own table; "label" is the name in its list.
            return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT label FROM %i WHERE id = %d', $wpdb->prefix . 'wsf_form', $form_id ) );
        }
        // Browser QA 18 Oct: Ninja Forms and Formidable entries read
        // "Ninja Forms / form 2" in the detail panel, {{form_title}} and the
        // privacy export, although the routing list already showed their names.
        $tables = [
            'ninja-forms'  => [ 'nf3_forms', 'title' ],
            'formidable'   => [ 'frm_forms', 'name' ],
            'fluent-forms' => [ 'fluentform_forms', 'title' ],
        ];
        if ( isset( $tables[ $slug ] ) ) {
            global $wpdb;
            // The form plugin may be gone while its entries stay: a missing table
            // means "no name", not a database error on the page.
            $quiet = $wpdb->suppress_errors( true );
            $title = $wpdb->get_var( $wpdb->prepare( 'SELECT %i FROM %i WHERE id = %d', $tables[ $slug ][1], $wpdb->prefix . $tables[ $slug ][0], $form_id ) );
            $wpdb->suppress_errors( $quiet );
            return (string) $title;
        }
        if ( 'forminator' === $slug && function_exists( 'get_post' ) ) {
            $post = get_post( $form_id );
            return ( $post && 'forminator_forms' === $post->post_type ) ? (string) $post->post_title : '';
        }
        if ( 'gravity-forms' === $slug && class_exists( '\\GFAPI' ) ) {
            $form = \GFAPI::get_form( $form_id );
            return is_array( $form ) ? (string) ( $form['title'] ?? '' ) : '';
        }
        return '';
    }
}
