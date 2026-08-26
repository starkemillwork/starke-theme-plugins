/**
 * External dependencies
 */
import React from 'react';
/**
 * Internal dependencies
 */
import './SkeletonV.scss';

/**
 * A reusable skeleton loader component that mimics the appearance
 * and animation of the built-in WooCommerce skeleton component.
 *
 * @param {Object}        props                  - The component props.
 * @param {string|number} [props.width]          - The width of the skeleton.
 * @param {string|number} [props.height]         - The height of the skeleton.
 * @param {string}        [props.className]      - Additional class names.
 * @param {boolean}       [props.isCircle=false] - If true, renders a circular skeleton.
 */
const SkeletonV = ({ width, height, className = '', isCircle = false }) => {
	const style = {
		width,
		height
	};

	let classes = `skeleton-block${isCircle ? ' skeleton-block--circle' : ''}`;
	if (className) {
		classes += ` ${className}`;
	}

	return <div className={classes} style={style}></div>;
};

export default SkeletonV;
