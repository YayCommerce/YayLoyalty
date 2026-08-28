export const downloadAsset = (url: string, fileName = '') => {
  const link = document.createElement('a');
  link.href = url.replace(/^http:\/\//i, 'https://');
  link.download = fileName || 'download';
  link.target = '_blank';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};
