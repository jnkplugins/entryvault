<?php
namespace EntryVault;

use EntryVault\Adapters\Adapter_Base;
use EntryVault\Adapters\Adapter_WPForms;
use EntryVault\Adapters\Adapter_Gravity_Forms;
use EntryVault\Adapters\Adapter_Forminator;
use EntryVault\Adapters\Adapter_Fluent_Forms;
use EntryVault\Adapters\Adapter_Trinity_Forms;
use EntryVault\Adapters\Adapter_Sureforms;
use EntryVault\Adapters\Adapter_CF7;
use EntryVault\Adapters\Adapter_Ninja_Forms;
use EntryVault\Adapters\Adapter_Formidable;
use EntryVault\Adapters\Adapter_WS_Form;
use EntryVault\Adapters\Adapter_Everest_Forms;
use EntryVault\Adapters\Adapter_Jetpack_Forms;
use EntryVault\Adapters\Adapter_Metform;
use EntryVault\Adapters\Adapter_Kadence_Forms;
use EntryVault\Adapters\Adapter_Otter_Forms;
use EntryVault\Adapters\Adapter_Spectra_Forms;
use EntryVault\Adapters\Adapter_Elementor_Forms;

defined( 'ABSPATH' ) || exit;

class Adapter_Registry {

    private static ?self $instance = null;

    /** @var Adapter_Base[] */
    private array $adapters = [];

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        $candidates = [
            Adapter_WPForms::class,
            Adapter_Gravity_Forms::class,
            Adapter_Forminator::class,
            Adapter_Fluent_Forms::class,
            Adapter_Trinity_Forms::class,
            Adapter_Sureforms::class,
            Adapter_CF7::class,
            Adapter_Ninja_Forms::class,
            Adapter_Formidable::class,
            Adapter_WS_Form::class,
            Adapter_Everest_Forms::class,
            Adapter_Jetpack_Forms::class,
            Adapter_Metform::class,
            Adapter_Kadence_Forms::class,
            Adapter_Otter_Forms::class,
            Adapter_Spectra_Forms::class,
            Adapter_Elementor_Forms::class,
        ];

        $candidates = apply_filters( 'entryvault_adapters', $candidates );

        foreach ( $candidates as $class ) {
            if ( ! class_exists( $class ) ) {
                continue;
            }
            $adapter = new $class();
            if ( ! $adapter instanceof Adapter_Base ) {
                continue;
            }
            if ( ! $adapter->is_active() ) {
                continue;
            }
            $adapter->register_hooks();
            $this->adapters[ $adapter->slug() ] = $adapter;
        }
    }

    public function get( string $slug ): ?Adapter_Base {
        return $this->adapters[ $slug ] ?? null;
    }

    /** @return Adapter_Base[] */
    public function all(): array {
        return $this->adapters;
    }
}
