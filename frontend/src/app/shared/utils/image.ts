/**
 * Reduz a foto no navegador antes do upload (celular gera 4–8 MB por foto).
 * createImageBitmap com imageOrientation "from-image" aplica a rotação do EXIF, então a foto
 * chega em pé no servidor e no PDF. Sem suporte, devolve o arquivo original.
 */
export async function resizeImage(file: File, maxSide = 1600, quality = 0.82): Promise<Blob> {
  if (typeof createImageBitmap !== 'function' || !file.type.startsWith('image/')) {
    return file;
  }

  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
    const width = Math.round(bitmap.width * scale);
    const height = Math.round(bitmap.height * scale);

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, width, height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
    return blob && blob.size < file.size ? blob : file;
  } catch {
    return file;
  }
}
