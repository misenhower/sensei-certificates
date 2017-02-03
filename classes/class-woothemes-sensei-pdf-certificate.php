<?php
/**
 * Sensei PDF Certificate Object Class
 *
 * All functionality pertaining to the idividual Certificate.
 *
 * @package WordPress
 * @subpackage Sensei
 * @category Extension
 * @author WooThemes
 * @since 1.0.0
 */

/**
 * TABLE OF CONTENTS
 *
 * - __construct()
 * - get_certificate_filename()
 * - generate_pdf()
 * - textarea_field()
 * - image_field()
 * - text_field()
 * - text_field_userdata()
 * - hex2rgb()
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Sensei PDF Certificate
 *
 * The Sensei PDF PCertificate class acts as a blueprint for all certificates.
 *
 * @since 1.0
 */
class WooThemes_Sensei_PDF_Certificate {

	/**
	 * @var int preview post id
	 */
	public $preview_id;

	/**
	 * @var int certificate hash
	 */
	public $hash;

	/**
	 * @var mixed certificate pdf data
	 */
	public $certificate_pdf_data;


	/**
	 * Construct certificate with $hash
	 *
	 * @access public
	 * @since 1.0.0
	 * @param int $certificate_hash Certificate hash
	 */
	public function __construct( $certificate_hash ) {

		$this->hash  = $certificate_hash;

		$this->certificate_pdf_data = apply_filters( 'woothemes_sensei_certificates_pdf_data', array(
			'font_color'   => '#000000',
			'font_size'    => '50',
			'font_style'   => '',
			'font_family'  => 'Helvetica'
		) );

		$this->certificate_pdf_data_userdata = apply_filters( 'woothemes_sensei_certificates_pdf_data_userdata', array(
			'font_color'   => '#666666',
			'font_size'    => '50',
			'font_style'   => 'I',
			'font_family'  => 'Times'
		) );

	} // End __construct()


	/**
	 * Returns the file name for this certificate
	 *
	 * @since 1.0.0
	 * @return string certificate pdf file name
	 */
	public function get_certificate_filename() {

		return 'certificate-' . $this->hash . '.pdf';

	} // End get_certificate_filename()

	/**
	 * Generate and save or stream a PDF file for this certificate
	 *
	 * @access public
	 * @since 1.0.0
	 * @param string $path optional absolute path to the certificate directory, if
	 *        not supplied the PDF will be streamed as a downloadable file
	 *
	 * @return mixed nothing if a $path is supplied, otherwise a PDF download
	 */
	public function generate_pdf( $path = '', $pagecount = 1 ) {

		// include the pdf library
		$root_dir = dirname( __FILE__ ) . DIRECTORY_SEPARATOR;
		require_once( $root_dir . '../lib/tfpdf/tfpdf.php' );

		do_action( 'sensei_certificates_set_background_image', $this );

		if (isset( $this->bg_image_src ) && '' != $this->bg_image_src ) {
			$image = $this->bg_image_src;
		} else {
			$image = apply_filters( 'woothemes_sensei_certificates_background', $GLOBALS['woothemes_sensei_certificates']->plugin_path . 'assets/images/certificate_template.png' );
		} // End If Statement

		$image_attr = getimagesize( $image );
		if ( $image_attr[0] > $image_attr[1] ) {
			$orientation = 'L';
		} else {
			$orientation = 'P';
		} // End If Statement


// Find certificate based on hash
        $args = array(
            'post_type' => 'certificate',
            'meta_key' => 'certificate_hash',
            'meta_value' => $this->hash
        );

        $query = new WP_Query( $args );
        $certificate_id = 0;

        if ( $query->have_posts() ) {

            $query->the_post();
            $certificate_id = $query->posts[0]->ID;

        } // End If Statement

        wp_reset_query();

        if ( 0 == intval( $certificate_id ) ) {
            wp_die( __( 'The certificate you are searching for does not exist.', 'sensei-certificates' ), __( 'Certificate Error', 'sensei-certificates' ) );
        }

        // Get Student Data
        $user_id = get_post_meta( $certificate_id, 'learner_id', true );
        $student = get_userdata( $user_id );

        // Get Course Data
        $course_id = get_post_meta( $certificate_id, 'course_id', true );
        $course = Sensei()->course->course_query( -1, 'usercourses', $course_id );
        $course = $course[0];

        // Get the certificate template
        $certificate_template_id = get_post_meta( $course_id, '_course_certificate_template', true );

        $certificate_template_custom_fields = get_post_custom( $certificate_template_id );

        // Define the data we're going to load: Key => Default value
        $load_data = array(
            'certificate_font_style'	=> array(),
            'certificate_font_color'	=> array(),
            'certificate_font_size'	=> array(),
            'certificate_font_family'	=> array(),
            'image_ids'            => array(),
            'certificate_template_fields'       => array(),
        );

        // Load the data from the custom fields
        foreach ( $load_data as $key => $default ) {

            // set value from db (unserialized if needed) or use default
            $this->$key = ( isset( $certificate_template_custom_fields[ '_' . $key ][0] ) && '' !== $certificate_template_custom_fields[ '_' . $key ][0] ) ? ( is_array( $default ) ? maybe_unserialize( $certificate_template_custom_fields[ '_' . $key ][0] ) : $certificate_template_custom_fields[ '_' . $key ][0] ) : $default;

        } // End For Loop

        // Data fields
        $data_fields = sensei_get_certificate_data_fields();


        // Create split images.
        $upload_dir = wp_upload_dir();

        $pos = strrpos($image,'.');
        if(!$pos)
            return;
        $type = substr($image,$pos+1);

        // Check for existence of split images first.
        if ( ! file_exists("{$image}_0.{$type}") ) {
            exec( "convert {$image} -crop 1x{$pagecount}@ +repage {$image}_%d.{$type}" );
        }

        $page_dims = array();

        for ( $i = 0; $i < $pagecount; $i++ ) {
            $page_dims[] = [ $image_attr['0'], $image_attr['1'] / $pagecount, 0, $i * ($image_attr['1'] / $pagecount) ];
        }

        // Create the pdf
        // TODO: we're assuming a standard DPI here of where 1 point = 1/72 inch = 1 pixel
        // When writing text to a Cell, the text is vertically-aligned in the middle
        $fpdf = new tFPDF( $orientation, 'pt', array( $page_dims[0][0], $page_dims[0][1] ) );

        $fpdf->AddPage();
        $fpdf->SetAutoPageBreak( false );

        // Add custom font
        $custom_font = apply_filters( 'sensei_certificates_custom_font', false );
        if( $custom_font ) {
            if( isset( $custom_font['family'] ) && isset( $custom_font['file'] ) ) {
                $fpdf->AddFont( $custom_font['family'], '', $custom_font['file'], true );
            }
            // Add custom fonts (new)
        } elseif ($custom_fonts = apply_filters('sensei_certificates_custom_fonts', [])) {
            foreach ($custom_fonts as $custom_font) {
                if (isset($custom_font['family']) && isset($custom_font['file'])) {
                    $fpdf->AddFont($custom_font['family'], '', $custom_font['file'], true);
                }
            }
        }

        // Add multibyte font
        $fpdf->AddFont( 'DejaVu', '', 'DejaVuSansCondensed.ttf', true );


        for ( $i = 0; $i < count ( $page_dims ); $i++ ) {
            // set the certificate image
            $fpdf->Image( "{$image}_{$i}.{$type}", 0, 0, $page_dims[$i][0], $page_dims[$i][1] );

            $show_border = apply_filters( 'woothemes_sensei_certificates_show_border', 0 );
            $start_position = 200;

            foreach ( $data_fields as $field_key => $field_info ) {

                $meta_key = 'certificate_' . $field_key;

                // Get the default field value
                $field_value = $field_info['text_placeholder'];
                if ( isset( $this->certificate_template_fields[$meta_key]['text'] ) && '' != $this->certificate_template_fields[$meta_key]['text'] ) {

                    $field_value = $this->certificate_template_fields[$meta_key]['text'];
                } // End If Statement

                // Replace the template tags
                $field_value = apply_filters( 'sensei_certificate_data_field_value', $field_value, $field_key, false, $student, $course );

                // Check if the field has a set position
                if ( isset( $this->certificate_template_fields[$meta_key]['position']['x1'] ) ) {

                    $y_min = $page_dims[$i][3];
                    $y_max = $i == count($page_dims) - 1 ? PHP_INT_MAX : $page_dims[$i + 1][3];

                    if ( $y_min > $this->certificate_template_fields[$meta_key]['position']['y1'] ||
                        $this->certificate_template_fields[$meta_key]['position']['y1'] > $y_max ) {
                        continue;
                    }

                    // Write the value to the PDF
                    $function_name = ( $field_info['type'] == 'textarea' ) ? 'textarea_field' : 'text_field';

                    $font_settings = $this->get_certificate_font_settings( $meta_key );

                    call_user_func_array(array($this, $function_name), array( $fpdf, $field_value, $show_border, array( $this->certificate_template_fields[$meta_key]['position']['x1'], $this->certificate_template_fields[$meta_key]['position']['y1'] - $y_min, $this->certificate_template_fields[$meta_key]['position']['width'], $this->certificate_template_fields[$meta_key]['position']['height'] ), $font_settings ));

                } // End If Statement

            } // End For Loop

            if ( $i < count( $page_dims ) - 1 ) {
                $fpdf->AddPage();
            }
        }

        if ( $path ) {
			// save the pdf as a file
			$fpdf->Output( $path, 'F' );
		} else {
			// download file
			$fpdf->Output( 'certificate-preview-' . $this->hash . '.pdf', 'I' );
		} // End If Statement

	} // End generate_pdf()


    /**
     * Returns font settings for the certificate template
     *
     * @access public
     * @since  1.0.0
     * @param string $field_key
     * @return array $return_array
     */
    public function get_certificate_font_settings( $field_key = '' ) {

        $return_array = array();

        if ( isset( $this->certificate_template_fields[$field_key]['font']['color'] ) && '' != $this->certificate_template_fields[$field_key]['font']['color'] ) {
            $return_array['font_color'] = $this->certificate_template_fields[$field_key]['font']['color'];
        } // End If Statement

        if ( isset( $this->certificate_template_fields[$field_key]['font']['family'] ) && '' != $this->certificate_template_fields[$field_key]['font']['family'] ) {
            $return_array['font_family'] = $this->certificate_template_fields[$field_key]['font']['family'];
        } // End If Statement

        if ( isset( $this->certificate_template_fields[$field_key]['font']['style'] ) && '' != $this->certificate_template_fields[$field_key]['font']['style'] ) {
            $return_array['font_style'] = $this->certificate_template_fields[$field_key]['font']['style'];
        } // End If Statement

        if ( isset( $this->certificate_template_fields[$field_key]['font']['size'] ) && '' != $this->certificate_template_fields[$field_key]['font']['size'] ) {
            $return_array['font_size'] = $this->certificate_template_fields[$field_key]['font']['size'];
        } // End If Statement

        return $return_array;

    } // End get_certificate_font_settings()


	/**
	 * Render a multi-line text field to the PDF
	 *
	 * @access public
	 * @since 1.0.0
	 * @param FPDF $fpdf fpdf library object
	 * @param string $field_name the field name
	 * @param mixed $value string or int value to display
	 * @param int $show_border a debugging/helper option to display a border
	 *        around the position for this field
	 */
	public function textarea_field( $fpdf, $value, $show_border, $position, $font = array() ) {

		if ( $value ) {

			if ( empty( $font ) ) {

				$font = array(
					'font_color' => $this->certificate_pdf_data['font_color'],
					'font_family' => $this->certificate_pdf_data['font_family'],
					'font_style' => $this->certificate_pdf_data['font_style'],
					'font_size' => $this->certificate_pdf_data['font_size']
				);

			} // End If Statement

			// Test each font element
			if ( empty( $font['font_color'] ) ) { $font['font_color'] = $this->certificate_pdf_data['font_color']; }
			if ( empty( $font['font_family'] ) ) { $font['font_family'] = $this->certificate_pdf_data['font_family']; }
			if ( empty( $font['font_style'] ) ) { $font['font_style'] = $this->certificate_pdf_data['font_style']; }
			if ( empty( $font['font_size'] ) ) { $font['font_size'] = $this->certificate_pdf_data['font_size']; }

			// get the field position
			list( $x, $y, $w, $h ) = $position;

			// font color
			$font_color = $this->hex2rgb( $font['font_color'] );
			$fpdf->SetTextColor( $font_color[0], $font_color[1], $font_color[2] );

			// Check for Border and Center align
			$border = 0;
			$center = 'J';
			if ( isset( $font['font_style'] ) && !empty( $font['font_style'] ) && false !== strpos( $font['font_style'], 'C' ) ) {
				$center = 'C';
				$font['font_style'] = str_replace( 'C', '', $font['font_style']);
			} // End If Statement
			if ( isset( $font['font_style'] ) && !empty( $font['font_style'] ) && false !== strpos( $font['font_style'], 'O' ) ) {
				$border = 1;
				$font['font_style'] = str_replace( 'O', '', $font['font_style']);
			} // End If Statement

			$custom_font = $this->set_custom_font( $fpdf, $font );

			// Set the field text styling based on the font type
			$fonttype = '';
			if( ! $custom_font ) {
				$fonttype = $this->get_font_type( $value );
				switch( $fonttype ) {
					case 'mb': $fpdf->SetFont('DejaVu','', $font['font_size']); break;
					case 'latin': $fpdf->SetFont( $font['font_family'], $font['font_style'], $font['font_size'] ); break;
					default: $fpdf->SetFont( $font['font_family'], $font['font_style'], $font['font_size'] ); break;
				}
			}

			$fpdf->setXY( $x, $y );

			if ( 0 < $border ) {
				$show_border = 1;
				$fpdf->SetDrawColor( $font_color[0], $font_color[1], $font_color[2] );
			}

			// Decode string based on font type
			if( 'latin' == $fonttype ) {
				$value = utf8_decode( $value );
			}

			// and write out the value
			$fpdf->Multicell( $w, $font['font_size'], $value, $show_border, $center );

		} // End If Statement

	} // End textarea_field()

	/**
	 * Render an image field to the PDF
	 *
	 * @access public
	 * @since 1.0.0
	 * @param FPDF $fpdf fpdf library object
	 * @param string $field_name the field name
	 * @param mixed $value string or int value to display
	 * @param int $show_border a debugging/helper option to display a border
	 *        around the position for this field
	 */
	public function image_field( $fpdf, $value, $show_border, $position ) {

		if ( $value ) {

			// get the field position
			list( $x, $y, $w, $h ) = $position;

			$fpdf->setXY( $x, $y );

			// and write out the value
			$fpdf->Image( esc_url( utf8_decode( $value ) ), $x, $y, $w, $h );

		} // End If Statement

	} // End image_field()


	/**
	 * Render a single-line text field to the PDF
	 *
	 * @access public
	 * @since 1.0.0
	 * @param FPDF $fpdf fpdf library object
	 * @param string $field_name the field name
	 * @param mixed $value string or int value to display
	 * @param int $show_border a debugging/helper option to display a border
	 *        around the position for this field
	 */
	public function text_field( $fpdf, $value, $show_border, $position, $font = array() ) {

		if ( $value ) {

			if ( empty( $font ) ) {

				$font = array(
					'font_color' => $this->certificate_pdf_data['font_color'],
					'font_family' => $this->certificate_pdf_data['font_family'],
					'font_style' => $this->certificate_pdf_data['font_style'],
					'font_size' => $this->certificate_pdf_data['font_size']
				);

			} // End If Statement

			// Test each font element
			if ( empty( $font['font_color'] ) ) { $font['font_color'] = $this->certificate_pdf_data['font_color']; }
			if ( empty( $font['font_family'] ) ) { $font['font_family'] = $this->certificate_pdf_data['font_family']; }
			if ( empty( $font['font_style'] ) ) { $font['font_style'] = $this->certificate_pdf_data['font_style']; }
			if ( empty( $font['font_size'] ) ) { $font['font_size'] = $this->certificate_pdf_data['font_size']; }

			// get the field position
			list( $x, $y, $w, $h ) = $position;

			// font color
			$font_color = $this->hex2rgb( $font['font_color'] );
			$fpdf->SetTextColor( $font_color[0], $font_color[1], $font_color[2] );

			// Check for Border and Center align
			$border = 0;
			$center = 'J';
			if ( isset( $font['font_style'] ) && !empty( $font['font_style'] ) && false !== strpos( $font['font_style'], 'C' ) ) {
				$center = 'C';
				$font['font_style'] = str_replace( 'C', '', $font['font_style']);
			} // End If Statement
			if ( isset( $font['font_style'] ) && !empty( $font['font_style'] ) && false !== strpos( $font['font_style'], 'O' ) ) {
				$border = 1;
				$font['font_style'] = str_replace( 'O', '', $font['font_style']);
			} // End If Statement

			$custom_font = $this->set_custom_font( $fpdf, $font );

			// Set the field text styling based on the font type
			$fonttype = '';
			if( ! $custom_font ) {
				$fonttype = $this->get_font_type( $value );
				switch( $fonttype ) {
					case 'mb': $fpdf->SetFont('DejaVu','', $font['font_size']); break;
					case 'latin': $fpdf->SetFont( $font['font_family'], $font['font_style'], $font['font_size'] ); break;
					default: $fpdf->SetFont( $font['font_family'], $font['font_style'], $font['font_size'] ); break;
				}
			}

			// show a border for debugging purposes
			if ( $show_border ) {
				$fpdf->setXY( $x, $y );
				$fpdf->Cell( $w, $h, '', 1 );
			} // End If Statement

			if ( 0 < $border ) {
				$show_border = 1;
				$fpdf->SetDrawColor( $font_color[0], $font_color[1], $font_color[2] );
			} // End If Statement

			// align the text to the bottom edge of the cell by translating as needed
			$y =$font['font_size'] > $h ? $y - ( $font['font_size'] - $h ) / 2 : $y + ( $h - $font['font_size'] ) / 2;
			$fpdf->setXY( $x, $y );

			// Decode string based on font type
			if( 'latin' == $fonttype ) {
				$value = utf8_decode( $value );
			}

			// and write out the value
			$fpdf->Cell( $w, $h, $value, $show_border, $position, $center  );

		} // End If Statement

	} // End text_field()

	/**
	 * Render a single-line text field to the PDF, with custom styling for the user data
	 *
	 * @access public
	 * @since 1.0.0
	 * @param FPDF $fpdf fpdf library object
	 * @param string $field_name the field name
	 * @param mixed $value string or int value to display
	 * @param int $show_border a debugging/helper option to display a border
	 *        around the position for this field
	 */
	public function text_field_userdata( $fpdf, $value, $show_border, $position, $font = array() ) {

		if ( $value ) {

			if ( empty( $font ) ) {

				$font = array(
					'font_color' => $this->certificate_pdf_data_userdata['font_color'],
					'font_family' => $this->certificate_pdf_data_userdata['font_family'],
					'font_style' => $this->certificate_pdf_data_userdata['font_style'],
					'font_size' => $this->certificate_pdf_data_userdata['font_size']
				);

			} // End If Statement

			// get the field position
			list( $x, $y, $w, $h ) = $position;

			// font color
			$font_color = $this->hex2rgb( $font['font_color'] );
			$fpdf->SetTextColor( $font_color[0], $font_color[1], $font_color[2] );

			$custom_font = $this->set_custom_font( $fpdf, $font );

			// Set the field text styling based on the font type
			$fonttype = '';
			if( ! $custom_font ) {
				$fonttype = $this->get_font_type( $value );
				switch( $fonttype ) {
					case 'mb': $fpdf->SetFont('DejaVu','', $font['font_size']); break;
					case 'latin': $fpdf->SetFont( $font['font_family'], $font['font_style'], $font['font_size'] ); break;
					default: $fpdf->SetFont( $font['font_family'], $font['font_style'], $font['font_size'] ); break;
				}
			}

			// show a border for debugging purposes
			if ( $show_border ) {
				$fpdf->setXY( $x, $y );
				$fpdf->Cell( $w, $h, '', 1 );
			} // End If Statement

			// align the text to the bottom edge of the cell by translating as needed
			$y =$font['font_size'] > $h ? $y - ( $font['font_size'] - $h ) / 2 : $y + ( $h - $font['font_size'] ) / 2;
			$fpdf->setXY( $x, $y );

			// Decode string based on font type
			if( 'latin' == $fonttype ) {
				$value = utf8_decode( $value );
			}

			// and write out the value
			$fpdf->Cell( $w, $h, $value );

		} // End If Statement

	} // End text_field_userdata()


	/**
	 * Taxes a hex color code and returns the RGB components in an array
	 *
	 * @access private
	 * @since 1.0.0
	 * @param string $hex hex color code, ie #EEEEEE
	 * @return array rgb components, ie array( 'EE', 'EE', 'EE' )
	 */
	private function hex2rgb( $hex ) {

		if ( ! $hex ) return '';

		$hex = str_replace( "#", "", $hex );

		if ( 3 == strlen( $hex ) ) {
			$r = hexdec( substr( $hex, 0, 1 ) . substr( $hex, 0, 1 ) );
			$g = hexdec( substr( $hex, 1, 1 ) . substr( $hex, 1, 1 ) );
			$b = hexdec( substr( $hex, 2, 1 ) . substr( $hex, 2, 1 ) );
		} else {
			$r = hexdec( substr( $hex, 0, 2 ) );
			$g = hexdec( substr( $hex, 2, 2 ) );
			$b = hexdec( substr( $hex, 4, 2 ) );
		} // End If Statement}

		return array( $r, $g, $b );

	} // End hex2rgb()

	/**
	 * Gets the font type (character set) of a string
	 *
	 * @access private
	 * @since  1.0.4
	 * @param  string $string String to check
	 * @return string         Font type
	 */
	public function get_font_type( $string = '' ) {

		if( ! $string ) return 'latin';

		if( mb_strlen( $string ) != strlen( $string ) ) {
			return 'mb';
		}

		return 'latin';

	}

	/**
	 * Set custom font
	 *
	 * @access private
	 * @since  1.0.4
	 * @param  object $fpdf         The FPDF object
	 * @param  array  $default_font The default font
	 * @return boolean 				True if the custom font was set
	 */
	public function set_custom_font( $fpdf, $default_font ) {

		$custom_font = apply_filters( 'sensei_certificates_custom_font', false );

		if( $custom_font ) {

			if( ! isset( $custom_font['family'] ) || ! $custom_font['family'] ) {
				$custom_font['family'] = $default_font['font_family'];
			}

			if( ! isset( $custom_font['size'] ) || ! $custom_font['size'] ) {
				$custom_font['size'] = $default_font['font_size'];
			}

			$fpdf->SetFont( $custom_font['family'], '', $custom_font['size'] );

			return true;
		}

		return false;
	} // End set_custom_font()

} // End Class
