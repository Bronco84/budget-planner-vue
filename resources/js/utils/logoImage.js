/**
 * Shrink an uploaded logo so it fits the account's custom_logo column
 * (65535 characters, enforced again by the server).
 *
 * Raster images are drawn onto a canvas of at most 128x128 (aspect ratio
 * preserved) and exported as the smallest of PNG / WebP that fits; if neither
 * fits, WebP then JPEG are re-encoded at decreasing quality. SVGs are kept
 * as-is when they already fit, otherwise rasterized the same way.
 */
export const LOGO_MAX_CHARS = 65535;
export const LOGO_MAX_DIMENSION = 128;

function readAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('Failed to read file'));
        reader.readAsDataURL(file);
    });
}

function loadImage(src) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Failed to load image'));
        image.src = src;
    });
}

function drawToCanvas(image, maxDimension, background = null) {
    // SVGs without intrinsic dimensions report 0; treat them as square.
    const width = image.naturalWidth || maxDimension;
    const height = image.naturalHeight || maxDimension;
    const scale = Math.min(1, maxDimension / Math.max(width, height));

    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));

    const context = canvas.getContext('2d');
    if (background) {
        context.fillStyle = background;
        context.fillRect(0, 0, canvas.width, canvas.height);
    }
    context.drawImage(image, 0, 0, canvas.width, canvas.height);

    return canvas;
}

/** canvas.toDataURL, or null when the browser does not support the type. */
function encode(canvas, type, quality) {
    const dataUrl = canvas.toDataURL(type, quality);

    return dataUrl.startsWith(`data:${type};`) ? dataUrl : null;
}

export async function resizeLogo(file, { maxChars = LOGO_MAX_CHARS, maxDimension = LOGO_MAX_DIMENSION } = {}) {
    const original = await readAsDataUrl(file);

    if (file.type === 'image/svg+xml' && original.length <= maxChars) {
        return original;
    }

    const image = await loadImage(original);
    const canvas = drawToCanvas(image, maxDimension);

    const fits = (dataUrl) => dataUrl && dataUrl.length <= maxChars;

    const lossless = [encode(canvas, 'image/png'), encode(canvas, 'image/webp', 0.92)]
        .filter(fits)
        .sort((a, b) => a.length - b.length);
    if (lossless.length) {
        return lossless[0];
    }

    // JPEG has no transparency, so flatten onto white first.
    const flattened = drawToCanvas(image, maxDimension, '#ffffff');
    for (const quality of [0.8, 0.6, 0.4, 0.25]) {
        const webp = encode(canvas, 'image/webp', quality);
        if (fits(webp)) {
            return webp;
        }
        const jpeg = encode(flattened, 'image/jpeg', quality);
        if (fits(jpeg)) {
            return jpeg;
        }
    }

    throw new Error('This image could not be made small enough. Please choose a simpler image.');
}
