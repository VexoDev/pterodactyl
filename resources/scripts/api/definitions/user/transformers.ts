import * as Models from '@definitions/user/models';
import { FractalResponseData } from '@/api/http';
import { transform } from '@definitions/helpers';

const normalizeFileAccess = (attributes: Record<string, any>) => {
    const normalized: Record<string, { allow: string[]; deny: string[] }> = {};
    const fileAccess = attributes.file_access || {};

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

        normalized[action] = { allow, deny };
    });

    return normalized;
};

export default class Transformers {
    static toSSHKey = (data: Record<any, any>): Models.SSHKey => {
        return {
            name: data.name,
            publicKey: data.public_key,
            fingerprint: data.fingerprint,
            createdAt: new Date(data.created_at),
        };
    };

    static toUser = ({ attributes }: FractalResponseData): Models.User => {
        return {
            uuid: attributes.uuid,
            username: attributes.username,
            email: attributes.email,
            image: attributes.image,
            twoFactorEnabled: attributes['2fa_enabled'],
            permissions: attributes.permissions || [],
            fileAccess: normalizeFileAccess(attributes),
            createdAt: new Date(attributes.created_at),
            can(permission): boolean {
                return this.permissions.includes(permission);
            },
        };
    };

    static toActivityLog = ({ attributes }: FractalResponseData): Models.ActivityLog => {
        const { actor } = attributes.relationships || {};

        return {
            id: attributes.id,
            batch: attributes.batch,
            event: attributes.event,
            ip: attributes.ip,
            isApi: attributes.is_api,
            description: attributes.description,
            properties: attributes.properties,
            hasAdditionalMetadata: attributes.has_additional_metadata ?? false,
            timestamp: new Date(attributes.timestamp),
            relationships: {
                actor: transform(actor as FractalResponseData, this.toUser, null),
            },
        };
    };
}

export class MetaTransformers {}
