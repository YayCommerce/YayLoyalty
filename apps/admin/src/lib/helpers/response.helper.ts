import { HTTPError } from 'ky';

export async function getErrorMsg(error: unknown, defaultMsg?: string) {
  let messageText = defaultMsg || 'An Error has occurred!';
  if ('string' === typeof error) {
    messageText = error;
  } else if (error instanceof HTTPError) {
    const errorData = await error.response.clone().json();
    messageText = errorData.message;
  } else if (error instanceof Error) {
    messageText = error.message;
  }
  return messageText;
}

export async function getErrorJson(error: unknown) {
  if (error instanceof HTTPError) {
    return await error.response.clone().json();
  }
  return null;
}
