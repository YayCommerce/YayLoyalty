import path from 'path';

export const users = {
	admin: {
		username: process.env.E2E_ADMIN_USER ?? 'admin',
		password: process.env.E2E_ADMIN_PASSWORD ?? 'password',
	},
	customer: { username: 'customer', password: 'password' },
};

export type Role = keyof typeof users;

export const storageState = ( role: Role ) => path.join( __dirname, '..', '.auth', `${ role }.json` );
