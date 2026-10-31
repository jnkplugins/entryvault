<?php
namespace EntryVault;

defined( 'ABSPATH' ) || exit;

/**
 * What an AI assistant may see of an entry.
 *
 * Entries hold other people's personal data, so by default an assistant gets
 * contact details masked: emails keep only the first letter and the domain,
 * phone numbers their last two digits, names their initials, and addresses and
 * IP addresses are left out. Fields that should never leave the site (files,
 * passwords, card numbers, signatures) are always left out, whatever the
 * setting. Forms the owner ticked under "Never show to assistants" are left out
 * entirely: their entries are not listed and cannot be opened.
 *
 * Masking is about what the assistant reads. It is not access control: the
 * Abilities API still checks the signed-in user's own rights on every call.
 */
class AI_Privacy {

    /** Field types masked when contact details are hidden. */
    private const MASK_TYPES = [ 'email', 'phone', 'tel', 'name', 'first_name', 'last_name', 'address', 'ip' ];

    /** Field types never shown to an assistant. */
    private const NEVER_TYPES = [ 'file', 'upload', 'password', 'signature', 'credit_card', 'card', 'payment' ];

    /** Labels that mean "sensitive" even when the type says text. */
    private const NEVER_LABELS = '/password|passwort|contraseña|비밀번호|पासवर्ड|كلمة المرور|card number|cvv|cvc|iban|ssn|social security|passport|national id/iu';

    private const NAME_LABELS    = '/\b(name|nombre|nom|이름|성명|नाम)\b|الاسم/iu';
    private const PHONE_LABELS   = '/phone|mobile|tel\b|teléfono|telefono|전화|휴대폰|फ़ोन|फोन|मोबाइल|هاتف|جوال/iu';
    private const ADDRESS_LABELS = '/address|street|dirección|direccion|주소|पता|العنوان/iu';

    public static function settings(): array {
        return (array) get_option( 'entryvault_settings', [] );
    }

    /** True unless the owner chose to show contact details to assistants. */
    public static function masking(): bool {
        return empty( self::settings()['ai_show_contact'] );
    }

    /** "plugin:form" keys the owner hid from assistants. */
    public static function hidden_forms(): array {
        return array_values( array_filter( array_map( 'strval', (array) ( self::settings()['ai_hidden_forms'] ?? [] ) ) ) );
    }

    public static function form_key( string $plugin, string $form_id ): string {
        return $plugin . ':' . $form_id;
    }

    public static function form_hidden( string $plugin, string $form_id ): bool {
        return in_array( self::form_key( $plugin, $form_id ), self::hidden_forms(), true );
    }

    /** May an assistant see this entry at all? */
    public static function entry_visible( array $row ): bool {
        return ! self::form_hidden( (string) ( $row['source_plugin'] ?? '' ), (string) ( $row['source_form_id'] ?? '' ) );
    }

    /** What kind of field this is for privacy: "never", "email", "phone", "name", "address" or "". */
    public static function field_kind( string $type, string $label ): string {
        // Form plugins spell types their own way: Fluent Forms says input_email
        // and input_name, others telephone, address_1, ip_address.
        $type = (string) preg_replace( '/^(input|field)_/', '', strtolower( $type ) );
        if ( in_array( $type, self::NEVER_TYPES, true ) || preg_match( '/file|upload|password|signature|credit|card/', $type ) || preg_match( self::NEVER_LABELS, $label ) ) {
            return 'never';
        }
        if ( false !== strpos( $type, 'email' ) ) {
            return 'email';
        }
        if ( preg_match( '/phone|^tel/', $type ) || preg_match( self::PHONE_LABELS, $label ) ) {
            return 'phone';
        }
        if ( preg_match( '/name$|^name/', $type ) || preg_match( self::NAME_LABELS, $label ) ) {
            return 'name';
        }
        if ( preg_match( '/address|^ip/', $type ) || preg_match( self::ADDRESS_LABELS, $label ) ) {
            return 'address';
        }
        return '';
    }

    public static function mask_email( string $email ): string {
        $email = trim( $email );
        $at    = strrpos( $email, '@' );
        if ( false === $at || 0 === $at ) {
            return '' === $email ? '' : '***';
        }
        return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
    }

    public static function mask_phone( string $phone ): string {
        $digits = preg_replace( '/\D+/', '', $phone );
        return strlen( (string) $digits ) < 3 ? '***' : '***' . substr( (string) $digits, -2 );
    }

    public static function mask_name( string $name ): string {
        $parts = preg_split( '/\s+/u', trim( $name ), -1, PREG_SPLIT_NO_EMPTY );
        return implode( ' ', array_map( static fn( $p ) => mb_substr( $p, 0, 1 ) . '.', (array) $parts ) );
    }

    /**
     * Mask what a contact field holds, by kind. Arrays (name parts, checkbox
     * lists) are joined first.
     */
    public static function mask_value( string $kind, string $value ): string {
        switch ( $kind ) {
            case 'email':
                return self::mask_email( $value );
            case 'phone':
                return self::mask_phone( $value );
            case 'name':
                return self::mask_name( $value );
            case 'address':
                return '[' . __( 'hidden', 'entryvault' ) . ']';
        }
        return $value;
    }

    /**
     * Take email addresses and phone numbers out of free text (a message, a
     * note, a title), after the exact contact values of the entry last passed
     * to prepare() (so "Jane Smith" in a message becomes "J. S.").
     */
    public static function scrub( string $text ): string {
        if ( ! self::masking() || '' === $text ) {
            return $text;
        }
        foreach ( self::$known_pairs as $raw => $masked ) {
            if ( mb_strlen( (string) $raw ) >= 3 ) {
                $text = str_ireplace( (string) $raw, $masked, $text );
            }
        }
        $text = (string) preg_replace_callback( '/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.\p{L}{2,}/u', static fn( $m ) => self::mask_email( $m[0] ), $text );
        $text = (string) preg_replace_callback( '/(?<![\p{L}\p{N}])\+?\d[\d\s().-]{6,}\d(?![\p{L}\p{N}])/u', static fn( $m ) => self::mask_phone( $m[0] ), $text );
        return $text;
    }

    /** raw contact value => masked form, for the entry being prepared. */
    private static array $known_pairs = [];

    /**
     * An entry's fields as an assistant may see them, plus the title and email
     * column with contact details taken out.
     *
     * @param array<int,array{field_key:string,field_label:string,field_type:string,value:string}> $fields
     * @return array{fields:array<int,array<string,string>>,title:string,email:string,left_out:int}
     */
    public static function prepare( array $row, array $fields ): array {
        // Rows come as field_label/field_type (detail query) or label/type
        // (Submission_Store::fields_for). Stored JSON (checkbox lists, a file
        // with its server path) is turned into readable text first.
        $fields = array_map( static function ( $f ) {
            $type = (string) ( $f['field_type'] ?? ( $f['type'] ?? '' ) );
            return [
                'field_label' => (string) ( $f['field_label'] ?? ( $f['label'] ?? '' ) ),
                'field_type'  => $type,
                'value'       => Field_Values::display( $f['value'] ?? '', $type ),
            ];
        }, $fields );
        $mask              = self::masking();
        self::$known_pairs = [];
        $out               = [];
        $left_out          = 0;
        // First pass: learn the contact values so free text can be scrubbed.
        foreach ( $fields as $f ) {
            $kind  = self::field_kind( (string) ( $f['field_type'] ?? '' ), (string) ( $f['field_label'] ?? '' ) );
            $value = (string) ( $f['value'] ?? '' );
            if ( $mask && in_array( $kind, [ 'email', 'phone', 'name', 'address' ], true ) && '' !== trim( $value ) ) {
                self::$known_pairs[ trim( $value ) ] = self::mask_value( $kind, $value );
                if ( 'name' === $kind ) {
                    foreach ( preg_split( '/\s+/u', trim( $value ), -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
                        if ( mb_strlen( $part ) >= 3 ) {
                            self::$known_pairs[ $part ] = mb_substr( $part, 0, 1 ) . '.';
                        }
                    }
                }
            }
        }
        $email = (string) ( $row['email'] ?? '' );
        if ( $mask && '' !== $email ) {
            self::$known_pairs[ $email ] = self::mask_email( $email );
        }
        uksort( self::$known_pairs, static fn( $a, $b ) => mb_strlen( (string) $b ) <=> mb_strlen( (string) $a ) );

        foreach ( $fields as $f ) {
            $label = (string) ( $f['field_label'] ?? '' );
            $kind  = self::field_kind( (string) ( $f['field_type'] ?? '' ), $label );
            if ( 'never' === $kind ) {
                $left_out++;
                continue;
            }
            $value = (string) ( $f['value'] ?? '' );
            if ( $mask ) {
                $value = '' !== $kind ? self::mask_value( $kind, $value ) : self::scrub( $value );
            }
            $out[] = [
                'label' => $label,
                'type'  => (string) ( $f['field_type'] ?? '' ),
                'value' => $value,
            ];
        }
        return [
            'fields'   => $out,
            'title'    => self::scrub( (string) ( $row['title'] ?? '' ) ),
            'email'    => $mask ? self::mask_email( $email ) : $email,
            'left_out' => $left_out,
        ];
    }

    /** Title and email for a list row, without loading every field. */
    public static function prepare_row( array $row ): array {
        $fields = Submission_Store::fields_for( (int) ( $row['id'] ?? 0 ) );
        $ready  = self::prepare( $row, $fields );
        return [ 'title' => $ready['title'], 'email' => $ready['email'] ];
    }

}
