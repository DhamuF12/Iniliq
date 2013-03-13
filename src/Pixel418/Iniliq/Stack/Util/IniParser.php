<?php

/* This file is part of the Iniliq project, which is under MIT license */

namespace Pixel418\Iniliq\Stack\Util;

class IniParser {


	/*************************************************************************
	  ATTRIBUTES				   
	 *************************************************************************/
	const ARRAY_OVERRIDE = 'ze5f4z65f43';
	protected $jsonValuesOption = TRUE;
	protected $deepSelectorOption = TRUE;
	protected $appendSelectorOption = TRUE;
	protected $arrayObjectOption = TRUE;
	protected $errorStrategy;



	/*************************************************************************
	  PUBLIC METHODS				   
	 *************************************************************************/
	public function parse( $files, $initialize = array( ) ) {
		\UArray::doConvertToArray( $files );
		$result = array( );
		if ( is_array( $initialize ) ) {
			array_unshift( $files, $initialize );
		}
		foreach ( $files as $file ) {
			$parsed = $this->parseIni( $file );
			$this->mergeValues( $result, $parsed );
		}
		if ( $this->arrayObjectOption ) {
			$options = array( $this->errorStrategy );
			if ( ! $this->deepSelectorOption ) {
				$options[ ] = \Pixel418\Iniliq::DISABLE_DEEP_SELECTORS;
			}
			$ArrayClass = \UObject::getNamespace( $this ) . '\\ArrayObject';
			$result = new $ArrayClass( $result, $options );
		}
		return $result;
	}

	public function setOptions( ) {
		$options = func_get_args( );
		if ( in_array( \Pixel418\Iniliq::DISABLE_JSON_VALUES, $options, TRUE ) ) {
			$this->jsonValuesOption = FALSE;
		} else if ( in_array( \Pixel418\Iniliq::ENABLE_JSON_VALUES, $options, TRUE ) ) {
			$this->jsonValuesOption = TRUE;
		}
		if ( in_array( \Pixel418\Iniliq::DISABLE_DEEP_SELECTORS, $options, TRUE ) ) {
			$this->deepSelectorOption = FALSE;
		} else if ( in_array( \Pixel418\Iniliq::ENABLE_DEEP_SELECTORS, $options, TRUE ) ) {
			$this->deepSelectorOption = TRUE;
		}
		if ( in_array( \Pixel418\Iniliq::DISABLE_APPEND_SELECTORS, $options, TRUE ) ) {
			$this->appendSelectorOption = FALSE;
		} else if ( in_array( \Pixel418\Iniliq::ENABLE_APPEND_SELECTORS, $options, TRUE ) ) {
			$this->appendSelectorOption = TRUE;
		}
		if ( in_array( \Pixel418\Iniliq::RESULT_AS_ARRAY, $options, TRUE ) ) {
			$this->arrayObjectOption = FALSE;
		} else if ( in_array( \Pixel418\Iniliq::RESULT_AS_ARRAY_OBJECT, $options, TRUE ) ) {
			$this->arrayObjectOption = TRUE;
		}
		if ( in_array( \Pixel418\Iniliq::ERROR_AS_EXCEPTION, $options, TRUE ) ) {
			$this->errorStrategy = \Pixel418\Iniliq::ERROR_AS_EXCEPTION;
		} else if ( in_array( \Pixel418\Iniliq::ERROR_AS_PHPERROR, $options, TRUE ) ) {
			$this->errorStrategy = \Pixel418\Iniliq::ERROR_AS_PHPERROR;
		} else {
			$this->errorStrategy = \Pixel418\Iniliq::ERROR_AS_QUIET;
		}
	}


	/*************************************************************************
	  CONSTRUCTOR METHODS				   
	 *************************************************************************/
	public function __construct( $options = array( ) ) {
		call_user_func_array( array( $this, 'setOptions' ), func_get_args( ) );
	}


	/*************************************************************************
	  PROTECTED METHODS				   
	 *************************************************************************/
	protected function parseIni( $file ) {
		$parsed = array( );
		if ( is_array( $file ) ) {
			$parsed = $file;
		} else if ( \UString::has( $file, PHP_EOL ) ) {
			$parsed = @parse_ini_string( $file, TRUE );
			if ( $parsed === FALSE ) {
				$this->manageError( 'The ini string has a bad format.' );
			}
		} else {
			if ( is_file( $file ) ) {
				$parsed = @parse_ini_file( $file, TRUE );
				if ( $parsed === FALSE ) {
					$this->manageError( 'The ini file has a bad format: ' . $file );
				}
			} else {
				$this->manageError( 'No such file or directory: ' . $file );
			}
		}
		return $parsed;
	}

	protected function mergeValues( &$reference, $values ) {
		if ( is_array( $values ) ) {
			foreach ( $values as $key => $value ) {
				$append = array( $key => $value );
				if ( $this->jsonValuesOption ) {
					$this->rewriteJsonValues( $append );
				}
				if ( $this->deepSelectorOption ) {
					$this->rewriteDeepSelectors( $append[ $key ] );
				}
				$reference[ $key ] = $append[ $key ];
				if ( $this->deepSelectorOption ) {
					$this->rewriteDeepSelectors( $reference );
				}
				if ( $this->appendSelectorOption ) {
					$this->rewriteAppendingSelectors( $reference );
				}
			}
		}
	}

	protected function rewriteJsonValues( &$values ) {
		if ( is_array( $values ) ) {
			foreach ( $values as $key => &$value ) {
				if ( ! is_array( $value ) && \UString::isStartWith( $value, array( '[', '{' ) ) ) {
					$json = preg_replace( array( '/([\[\]\{\}:,])\s*(\w)/', '/(\w)\s*([\[\]\{\}:,])/' ), '\1"\2', $value );
					$array = json_decode( $json, TRUE );
					if ( $array !== FALSE ) {
						$value = $array;
					}
				}
				$this->rewriteJsonValues( $value );
			}
		}
	}

	protected function rewriteDeepSelectors( &$values ) {
		if ( is_array( $values ) ) {
			foreach ( $values as $key => &$value ) {
				$this->rewriteDeepSelectors( $value );
				if ( \Pixel418\Iniliq::isDeepSelector( $key ) ) {
					$values = \UArray::setDeepSelector( $values, $key, $value );
					unset( $values[ $key ] );
				}
			}
		}
	}

	protected function rewriteAppendingSelectors( &$values ) {
		if ( is_array( $values ) ) {
			foreach ( $values as $key => &$value ) {
				if ( preg_match( '/\s*\+\s*$/', $key ) > 0 ) {
					$this->appendingValues( $values, $key );
					$value =& $values[ $key ];
				} else if ( preg_match( '/\s*-\s*$/', $key ) > 0 ) {
					$this->removingValues( $values, $key );
					$value =& $values[ $key ];
				}
				if ( is_array( $value ) ) {
					$this->rewriteAppendingSelectors( $value );
				}
			}
		}
	}

	protected function appendingValues( &$values, &$key ) {
		$reference_key = preg_replace( '/\s*\+\s*$/', '', $key );
		\UArray::doConvertToArray( $values[ $reference_key ] );
		\UArray::doConvertToArray( $values[ $key ] );
		$append = array( $reference_key => $values[ $key ] );
		unset( $values[ $key ] );
		\UArray::doMergeRecursive( $values, $append );
		$key = $reference_key;
	}

	protected function removingValues( &$values, &$key ) {
		$reference_key = preg_replace( '/\s*-\s*$/', '', $key );
		$reference =& $values[ $reference_key ];
		$remove = $values[ $key ];
		unset( $values[ $key ] );
		\UArray::doConvertToArray( $reference );
		\UArray::doRemoveValue( $reference, $remove );
		$key = $reference_key;
	}

	protected function manageError( $message ) {
		if ( $this->errorStrategy == \Pixel418\Iniliq::ERROR_AS_EXCEPTION ) {
			throw new \Exception( $message );
		} else if ( $this->errorStrategy == \Pixel418\Iniliq::ERROR_AS_PHPERROR ) {
			trigger_error( $message );
		}
	}
}