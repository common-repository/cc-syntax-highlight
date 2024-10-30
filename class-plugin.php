<?php

/*
    Copyright (C) 2022 by Clearcode <http://clearcode.cc>
    and associates (see AUTHORS.txt file).

    This file is part of CC-Syntax-Highlight.

    CC-Syntax-Highlight is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    CC-Syntax-Highlight is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with CC-Syntax-Highlight; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace Clearcode;

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! class_exists( __NAMESPACE__ . '\Syntax_Highlight' ) ) {
    class Syntax_Highlight extends Syntax_Highlight\Singleton {
        static protected $plugin    = null;

        protected $shortcode        = 'code';
        protected $post_types       = [ 'post', 'page' ];
        protected $syntax_highlight = 'highlight';
        protected $style            = 'tomorrow-night';
        protected $clipboard        = true;

        static public function get( $name = null ) {
            $dir  = str_replace( '\\', '/', plugin_dir_path( __FILE__ ) );
            $file = $dir . 'plugin.php';

            if ( null === self::$plugin ) self::$plugin = get_plugin_data( $file );
            if ( null === $name )         return self::$plugin;

            if ( 'slug' === strtolower( $name ) ) return __CLASS__;
            if ( 'file' === strtolower( $name ) ) return $file;
            if ( 'dir'  === strtolower( $name ) ) return $dir;

            if ( ! empty( self::$plugin[$name] ) ) return self::$plugin[$name];
            return null;
        }

        static public function get_template( $template, $vars = [] ) {
            $template = apply_filters( self::get( 'slug' ) . '\template', $template, $vars );
            if ( ! is_file( $template ) ) return false;

            $vars = apply_filters( self::get( 'slug' ) . '\vars', $vars, $template );
            if ( is_array( $vars ) ) extract( $vars, EXTR_SKIP );

            ob_start();
            include $template;

            return ob_get_clean();
        }

        static public function get_files( $dir, $extension ) {
            $files = glob( trailingslashit( self::get( 'dir' ) . $dir ) . '*.' . $extension );
            return array_map( function( $file ) use( $extension ) {
                $file = basename( $file );
                return substr( $file, 0, -( strlen( $extension ) + 1 ) );
            }, $files );
        }

        public function __get( $name ) {
            if ( isset( $this->$name ) ) return $this->$name;
        }

        protected function __construct() {
            register_activation_hook(   self::get( 'file' ), [ $this, 'activation' ] );
            register_deactivation_hook( self::get( 'file' ), [ $this, 'deactivation' ] );
            
            add_action( 'init',       [ $this, 'init' ] );
            add_action( 'admin_init', [ $this, 'admin_init' ] );
            add_action( 'admin_menu', [ $this, 'admin_menu' ], 999 );

            add_filter( 'plugin_row_meta', [ $this, 'plugin_row_meta' ], 10, 4 );
            add_filter( 'plugin_action_links_' . plugin_basename( self::get( 'file' ) ), [ $this, 'plugin_action_links' ] );
        }
        
        public function activation() {
            update_option( self::get( 'slug' ), [
                'version'          => self::get( 'Version' ),
                'shortcode'        => $this->shortcode,
                'post_types'       => $this->post_types,
                'syntax_highlight' => $this->syntax_highlight,
                'style'            => $this->style,
                'clipboard'        => true
            ] );
        }
        
        public function deactivation() {
            delete_option( self::get( 'slug' ) );
        }

        public function plugin_row_meta( $plugin_meta, $plugin_file, $plugin_data, $status ) {
            if ( empty( self::get( 'Name' )  ) ) return $plugin_meta;
            if ( empty( $plugin_data['Name'] ) ) return $plugin_meta;
            if ( self::get( 'Name' ) == $plugin_data['Name'] ) $plugin_meta[] = sprintf( '%s <a href="http://piotr.press" target="_blank">PiotrPress</a>', __( 'Author', self::get( 'TextDomain' ) ) );
            return $plugin_meta;
        }

        public function plugin_action_links( $links ) {
            array_unshift( $links, sprintf( '<a href="%s">%s</a>', get_admin_url( null, 'options-general.php?page=syntax_highlight' ), __( 'Settings', self::get( 'TextDomain' ) ) ) );
            return $links;
        }
        
        public function init() {
            if ( $options = get_option( self::get( 'slug' ) ) )
                foreach( [ 'post_types', 'syntax_highlight', 'shortcode', 'style', 'clipboard' ] as $option )
                    if ( isset( $options[$option] ) ) $this->$option = $options[$option];

            // Hack from wp-includes/class-wp-embed.php
            $this->shortcode = apply_filters( self::get( 'slug' ) . '\shortcode', $this->shortcode );
            add_filter( 'the_content', [ $this, 'escape' ], 0 );
            add_filter( 'the_content', [ $this, 'do_shortcode' ], 8 ); // Hack to get the [code] shortcode to run before wpautop()
            add_shortcode( $this->shortcode, '__return_false' ); // Shortcode placeholder for strip_shortcodes()
            add_filter( 'no_texturize_shortcodes', function( $shortcodes ) { return array_merge( [ $this->shortcode ], $shortcodes ); } );

            add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ] );
        }

        public function escape( $content ) {
            if ( is_admin() ) return $content;

            $pattern = sprintf( '/\[%s\](.*?)\[\/%s\]/s', $this->shortcode, $this->shortcode );
            return preg_replace_callback( $pattern, function( $matches ) {
                $content  = $matches[1];
                $content  = htmlentities( $content, null, get_bloginfo( 'charset' ) );
                $content  = str_replace( [ '[', ']' ], [ '&lsqb;', '&rsqb;' ], $content );
                return sprintf( '[%s]%s[/%s]', $this->shortcode, $content, $this->shortcode );
            }, $content );
        }

        /**
         * Process the [code] shortcode.
         *
         * Since the [code] shortcode needs to be run earlier than other shortcodes,
         * this function removes all existing shortcodes, registers the [code] shortcode,
         * calls {@link do_shortcode()}, and then re-registers the old shortcodes.
         *
         * @global array $shortcode_tags
         *
         * @param string $content Content to parse
         * @return string Content with shortcode parsed
         */
        public function do_shortcode( $content ) {
            if ( is_admin() ) return $content;
            global $shortcode_tags;

            // Back up current registered shortcodes and clear them all out
            $shortcodes = $shortcode_tags;
            remove_all_shortcodes();

            add_shortcode( $this->shortcode, [ $this, 'shortcode' ] );

            // Do the shortcode (only the [code] one is registered)
            $content = do_shortcode( $content, true );

            // Put the original shortcodes back
            $shortcode_tags = $shortcodes;

            return $content;
        }

        public function shortcode( $atts = [], $content = '' ) {
            if ( empty( $content ) ) return '';

            return sprintf( '<code>%s</code>', $content );
        }

        public function admin_menu() {
            add_options_page(
                __( 'Syntax Highlight', self::get( 'TextDomain' ) ),
                sprintf( '<div id="%s" class="%s"> %s</div>', self::get('slug'), 'dashicons-before dashicons-admin-appearance', __( 'Syntax Highlight', self::get( 'TextDomain' ) ) ),
                'manage_options',
                'syntax_highlight',
                [ $this, 'settings_page' ]
            );
        }

        public function admin_init() {
            register_setting(     'syntax_highlight', self::get( 'slug' ), [ $this, 'sanitize' ] );
            add_settings_section( 'syntax_highlight', __( 'Syntax Highlight', self::get( 'TextDomain' ) ), [ $this, 'settings_section' ], 'syntax_highlight' );

            foreach( [
                'post_types'       => __( 'Post Types',        self::get( 'TextDomain' ) ),
                'syntax_highlight' => __( 'Syntax Highlight',  self::get( 'TextDomain' ) ),
                'style'            => __( 'Style',             self::get( 'TextDomain' ) ),
                'shortcode'        => __( 'Shortcode',         self::get( 'TextDomain' ) ),
                'clipboard'        => __( 'Copy to Clipboard', self::get( 'TextDomain' ) ),
            ] as $field => $label ) add_settings_field( self::get( 'slug' ) . '_settings_' . $field, $label, [ $this, 'settings_' . $field ], 'syntax_highlight', 'syntax_highlight' );
        }
        
        public function wp_enqueue_scripts() {
            global $post;
            if ( is_404() or ! $post ) return;
            if ( ! in_array( $post->post_type, $this->post_types ) ) return;
            if ( ! has_shortcode( $post->post_content, $this->shortcode ) && false === strpos( $post->post_content, sprintf( '<%s>', $this->shortcode ) ) ) return;

            'prettify' == $this->syntax_highlight ? $this->prettify() : $this->highlight();

            $dependencies = [ 'jquery', $this->syntax_highlight ];
            wp_enqueue_script( 'syntax_highlight', plugins_url( $this->syntax_highlight . '/syntax_highlight.js', self::get( 'file' ) ), $dependencies, self::get( 'Version' ), true );

            if ( $this->clipboard ) {
                wp_enqueue_style(  'copy-to-clipboard', plugins_url( 'clipboard/clipboard.css',    self::get( 'file' ) ),                       [],                       self::get( 'Version' ) );
                wp_enqueue_script( 'copy-to-clipboard', plugins_url( 'clipboard/clipboard.min.js', self::get( 'file' ) ),          $dependencies[] = 'syntax_highlight',  self::get( 'Version' ), true );
                wp_enqueue_script( 'clipboard_run',              plugins_url( 'clipboard/clipboard.js',     self::get( 'file' ) ), $dependencies[] = 'copy-to-clipboard', self::get( 'Version' ), true );
            }
        }
        
        protected function highlight() {
            if ( apply_filters( self::get( 'slug' ) . '\cdn', false ) ) {
                wp_enqueue_style(  'highlight',    '//cdnjs.cloudflare.com/ajax/libs/highlight.js/9.15.10/styles/default.min.css',                       [], self::get( 'Version' ) );
                wp_enqueue_script( 'highlight',    '//cdnjs.cloudflare.com/ajax/libs/highlight.js/9.15.10/highlight.min.js',                             [], self::get( 'Version' ) );
                wp_enqueue_script( 'line-numbers', '//cdnjs.cloudflare.com/ajax/libs/highlightjs-line-numbers.js/2.7.0/highlightjs-line-numbers.min.js', [], self::get( 'Version' ) );
            } else {
                wp_enqueue_script( 'highlight',    plugins_url( 'highlight/highlight.pack.js',                   self::get( 'file' ) ),                 [], self::get( 'Version' ) );
                wp_enqueue_script( 'line-numbers', plugins_url( 'line-numbers/highlightjs-line-numbers.min.js',  self::get( 'file' ) ),    [ 'highlight' ], self::get( 'Version' ) );
                wp_enqueue_style(  'line-numbers', plugins_url( 'line-numbers/highlightjs-line-numbers.min.css', self::get( 'file' ) ),                 [], self::get( 'Version' ) );
                if( in_array( $style = apply_filters( self::get( 'slug' ) . '\style', $this->style ), self::get_files( 'highlight', 'css' ) ) )
                    wp_enqueue_style( $style, plugins_url( "highlight/$style.css", self::get( 'file' ) ), [], self::get( 'Version' ) );
            }
        }
        
        protected function prettify() {
            if ( apply_filters( self::get( 'slug' ) . '\cdn', false ) )
                wp_enqueue_script( 'prettify', 'https://google-code-prettify.googlecode.com/svn/loader/run_prettify.js', [], self::get( 'Version' ) );
            elseif( apply_filters( self::get( 'slug' ) . '\autoload', false ) )
                wp_enqueue_script( 'prettify', plugins_url( 'prettify/run_prettify.js', self::get( 'file' ) ),          [], self::get( 'Version' ) );
            else {
                wp_enqueue_style(  'prettify', plugins_url( 'prettify/prettify.css',    self::get( 'file' ) ),          [], self::get( 'Version' ) );
                wp_enqueue_script( 'prettify', plugins_url( 'prettify/prettify.js',     self::get( 'file' ) ),          [], self::get( 'Version' ) );
            }
            if ( in_array( $style = apply_filters( self::get( 'slug' ) . '\style', $this->style ), self::get_files( 'prettify', 'css' ) ) )
                wp_enqueue_style( $style, plugins_url( "prettify/$style.css", self::get( 'file' ) ), ['prettify' ], self::get( 'Version' ) );
        }

        protected function get_post_types( $output ) {
            switch( $output ) {
                case 'objects':
                    $post_types = [ get_post_type_object( 'post' ), get_post_type_object( 'page' ) ];
                    return array_merge( $post_types, get_post_types( [ '_builtin' => false ], 'objects' ) );
                case 'names':
                default:
                    return array_merge( [ 'post', 'page' ], get_post_types( [ '_builtin' => false ] ) );
            }
        }
        
        public function sanitize( $options ) {
            $sanitized_options = [];
            $sanitized_options['version']          = self::get( 'Version' );
            $sanitized_options['post_types']       = array_intersect( (array)$options['post_types'], $this->get_post_types( 'names' ) );
            $sanitized_options['shortcode']        = sanitize_title( $options['shortcode'] );
            $sanitized_options['syntax_highlight'] = ! in_array( $options['syntax_highlight'], [ 'highlight', 'prettify' ] ) ? 'highlight' : $options['syntax_highlight'];
            $sanitized_options['clipboard']        = ! empty( $options['clipboard'] ) ? true : false;

            if ( in_array( $options['style'], self::get_files( $sanitized_options['syntax_highlight'], 'css' ) ) )
                $sanitized_options['style'] = $options['style'];
            elseif( 'highlight' == $sanitized_options['syntax_highlight'] )
                $sanitized_options['style'] = 'tomorrow-night';
            else
                $sanitized_options['style'] = 'sunburst';

            return $sanitized_options;
        }

        public function settings_page() {
            echo '<div class="wrap"><form method="post" action="options.php">';
            settings_fields(      'syntax_highlight' );
            do_settings_sections( 'syntax_highlight' );
            submit_button();
            echo '</form>' .
                 '<p>' . __( 'Use the standard HTML block of code tags', self::get( 'TextDomain' ) ) . ': <pre><code>' .
                 htmlentities( '<pre><code>&lt;?= &quot;' . __( 'Hello world!', self::get( 'TextDomain' ) ) . ';&quot; ?&gt;</code></pre>' ) .
                 '</code></pre></p>' .
                 '<p>' . __( 'Or use shortcode block of code', self::get( 'TextDomain' ) ) . ': <pre><code>' .
                 htmlentities( '<pre>[' . $this->shortcode . ']<?= "' . __( 'Hello world!', self::get( 'TextDomain' ) ) . ';" ?>[/' . $this->shortcode . ']</pre>' ) .
                 '</code></pre></p>' .
                 '</div>';
        }

        public function settings_section() {
            printf( '<p>%s</p>', __( 'Settings', self::get( 'TextDomain' ) ) );
        }
        
        public function settings_post_types() {
            $post_types = [];
            foreach( $this->get_post_types( 'objects' ) as $post_type ) $post_types[$post_type->labels->name] = $post_type->name;
            $this->input( 'checkbox', 'post_types', $post_types );
        }
        
        public function settings_syntax_highlight() {
            $this->input( 'radio', 'syntax_highlight', [ 'Highlight' => 'highlight', 'Prettify' => 'prettify' ] );
        }

        public function settings_shortcode() {
            $this->input( 'text', 'shortcode', [ $this->shortcode ] );
        }

        public function settings_style() {
            $options = [];
            if ( $files = self::get_files( $this->syntax_highlight, 'css' ) )
                foreach( $files as $file ) {
                    $style = $file;
                    foreach( [ '-', '_', '.' ] as $separator )
                        $style = str_replace( $separator, ' ', $style );

                    $options[$file] = ucfirst( $style );
                }

            $this->select( 'style', $options );
        }

        public function settings_clipboard() {
            $this->input( 'radio', 'clipboard', [ __( 'Enable', self::get( 'TextDomain' ) ) => true, __( 'Disable', self::get( 'TextDomain' ) ) => false ] );
        }

        protected function input( $type, $option, $options ) {
            $input = '<label><input type="%s" id="%s" name="%s" value="%s" %s /> %s</label></br>';

            foreach( $options as $key => $value ) {
                if ( empty( $key ) ) $id = sprintf( '%s\%s', self::get( 'slug' ), $option );
                else $id = sprintf( '%s\%s\%s', self::get( 'slug' ), $option, lcfirst( $key ) );

                $name = sprintf( '%s[%s]', self::get( 'slug' ), $option );

                if ( is_array( $this->$option ) ) {
                    $name .= '[]';
                    $checked = checked( in_array( $value, $this->$option ), true, false );
                } else $checked = checked( $this->$option, $value, false );
                if ( ! in_array( $type, [ 'checkbox', 'radio' ] ) ) $checked = '';

                if ( empty( $key ) ) printf( $input, $type, $id, $name, $value, $checked, '' );
                else printf( $input, $type, $id, $name, $value, $checked, __( $key, self::get( 'slug' ) ) );
            }
        }

        protected function select( $option, $options ) {
            $id   = sprintf( '%s\%s',  self::get( 'slug' ), $option );
            $name = sprintf( '%s[%s]', self::get( 'slug' ), $option );

            $select = sprintf( '<select id="%s" name="%s">%s</select>', $id, $name, '%s' );
            $item   = '<option value="%s" %s>%s</option>';

            $items = '';
            foreach( $options as $key => $value ) {
                $checked = selected( $key == $this->$option, true, false );
                $items  .= sprintf( $item, $key, $checked, __( $value, self::get( 'slug' ) ) );
            }

            printf( $select, $items );
        }
    }
}
