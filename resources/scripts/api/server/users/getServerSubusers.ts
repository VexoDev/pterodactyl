import http, { FractalResponseData } from '@/api/http';
import { Subuser, SubuserFileAccess } from '@/state/server/subusers';

const normalizeFileAccess = (data: Record<string, any>): SubuserFileAccess => {
    const normalized: SubuserFileAccess = {};
    const fileAccess = data.attributes.file_access || {};

    if (typeof fileAccess !== 'object' || fileAccess === null) {
        return normalized;
    }

    Object.keys(fileAccess).forEach((action) => {
        const value = fileAccess[action];
        if (typeof value !== 'object' || value === null) {
            return;
        }

        const allow = Array.isArray(value.allow) ? value.allow.filter((entry) => typeof entry === 'string') : [];
        const deny = Array.isArray(value.deny) ? value.deny.filter((entry) => typeof entry === 'string') : [];

        if (!allow.length && !deny.length) {
            return;
        }

        (normalized as Record<string, { allow: string[]; deny: string[] }>)[action] = { allow, deny };
    });

    return normalized;
};

export const rawDataToServerSubuser = (data: FractalResponseData): Subuser => ({
    uuid: data.attributes.uuid,
    username: data.attributes.username,
    email: data.attributes.email,
    image: data.attributes.image,
    twoFactorEnabled: data.attributes['2fa_enabled'],
    createdAt: new Date(data.attributes.created_at),
    permissions: data.attributes.permissions || [],
    fileAccess: normalizeFileAccess(data),
    can: (permission) => (data.attributes.permissions || []).indexOf(permission) >= 0,
});

export default (uuid: string): Promise<Subuser[]> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/users`)
            .then(({ data }) => resolve((data.data || []).map(rawDataToServerSubuser)))
            .catch(reject);
    });
};
