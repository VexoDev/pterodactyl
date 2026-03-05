import React, { useContext, useEffect, useRef } from 'react';
import { Subuser, SubuserFileAccess, SubuserFileAccessAction } from '@/state/server/subusers';
import { Field as FormikField, Form, Formik } from 'formik';
import { array, object, string } from 'yup';
import Field from '@/components/elements/Field';
import { Actions, useStoreActions, useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import createOrUpdateSubuser from '@/api/server/users/createOrUpdateSubuser';
import { ServerContext } from '@/state/server';
import FlashMessageRender from '@/components/FlashMessageRender';
import Can from '@/components/elements/Can';
import { usePermissions } from '@/plugins/usePermissions';
import { useDeepCompareMemo } from '@/plugins/useDeepCompareMemo';
import tw from 'twin.macro';
import Button from '@/components/elements/Button';
import PermissionTitleBox from '@/components/server/users/PermissionTitleBox';
import asModal from '@/hoc/asModal';
import PermissionRow from '@/components/server/users/PermissionRow';
import ModalContext from '@/context/ModalContext';
import { Textarea } from '@/components/elements/Input';
import Label from '@/components/elements/Label';

type Props = {
    subuser?: Subuser;
};

type FileAccessTextRule = {
    allow: string;
    deny: string;
};

type FileAccessTextValues = Record<SubuserFileAccessAction, FileAccessTextRule>;

interface Values {
    email: string;
    permissions: string[];
    fileAccess: FileAccessTextValues;
}

const FILE_ACCESS_ACTIONS: Array<{
    action: SubuserFileAccessAction;
    permission: string;
    title: string;
    description: string;
}> = [
    {
        action: 'read',
        permission: 'file.read',
        title: 'Directory Listing',
        description: 'Applies to browsing folder contents.',
    },
    {
        action: 'read-content',
        permission: 'file.read-content',
        title: 'Read File Contents',
        description: 'Applies to reading and downloading files.',
    },
    {
        action: 'create',
        permission: 'file.create',
        title: 'Create Files/Folders',
        description: 'Applies to creating files/folders, uploads, pull, and decompress actions.',
    },
    {
        action: 'update',
        permission: 'file.update',
        title: 'Update Files/Folders',
        description: 'Applies to rename/move and chmod actions.',
    },
    {
        action: 'delete',
        permission: 'file.delete',
        title: 'Delete Files/Folders',
        description: 'Applies to deleting files and folders.',
    },
    {
        action: 'archive',
        permission: 'file.archive',
        title: 'Archive Actions',
        description: 'Applies to compress actions.',
    },
];

const emptyFileAccessValues = (): FileAccessTextValues =>
    FILE_ACCESS_ACTIONS.reduce(
        (carry, entry) => ({
            ...carry,
            [entry.action]: { allow: '', deny: '' },
        }),
        {} as FileAccessTextValues
    );

const splitRegexLines = (value: string): string[] =>
    Array.from(
        new Set(
            value
                .split(/\r?\n/)
                .map((line) => line.trim())
                .filter((line) => line.length > 0)
        )
    );

const fileAccessToForm = (fileAccess?: SubuserFileAccess): FileAccessTextValues => {
    const values = emptyFileAccessValues();

    FILE_ACCESS_ACTIONS.forEach(({ action }) => {
        const allow = fileAccess?.[action]?.allow || [];
        const deny = fileAccess?.[action]?.deny || [];

        values[action] = {
            allow: allow.join('\n'),
            deny: deny.join('\n'),
        };
    });

    return values;
};

const fileAccessToPayload = (values: FileAccessTextValues, permissions: string[]): SubuserFileAccess => {
    const payload: SubuserFileAccess = {};
    const selectedPermissions = new Set(permissions);

    FILE_ACCESS_ACTIONS.forEach(({ action, permission }) => {
        if (!selectedPermissions.has(permission)) {
            return;
        }

        const allow = splitRegexLines(values[action].allow);
        const deny = splitRegexLines(values[action].deny);

        if (!allow.length && !deny.length) {
            return;
        }

        payload[action] = { allow, deny };
    });

    return payload;
};

const fileAccessValidation = FILE_ACCESS_ACTIONS.reduce<Record<string, any>>((carry, entry) => {
    carry[entry.action] = object().shape({
        allow: string(),
        deny: string(),
    });

    return carry;
}, {});

const EditSubuserModal = ({ subuser }: Props) => {
    const ref = useRef<HTMLHeadingElement>(null);
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const appendSubuser = ServerContext.useStoreActions((actions) => actions.subusers.appendSubuser);
    const { clearFlashes, clearAndAddHttpError } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );
    const { dismiss, setPropOverrides } = useContext(ModalContext);

    const isRootAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const permissions = useStoreState((state) => state.permissions.data);
    // The currently logged in user's permissions. We're going to filter out any permissions
    // that they should not need.
    const loggedInPermissions = ServerContext.useStoreState((state) => state.server.permissions);
    const [canEditUser] = usePermissions(subuser ? ['user.update'] : ['user.create']);

    // The permissions that can be modified by this user.
    const editablePermissions = useDeepCompareMemo(() => {
        const cleaned = Object.keys(permissions).map((key) =>
            Object.keys(permissions[key].keys).map((pkey) => `${key}.${pkey}`)
        );

        const list: string[] = ([] as string[]).concat.apply([], Object.values(cleaned));

        if (isRootAdmin || (loggedInPermissions.length === 1 && loggedInPermissions[0] === '*')) {
            return list;
        }

        return list.filter((key) => loggedInPermissions.indexOf(key) >= 0);
    }, [isRootAdmin, permissions, loggedInPermissions]);

    const submit = (values: Values) => {
        setPropOverrides({ showSpinnerOverlay: true });
        clearFlashes('user:edit');

        createOrUpdateSubuser(
            uuid,
            {
                ...values,
                fileAccess: fileAccessToPayload(values.fileAccess, values.permissions),
            },
            subuser
        )
            .then((subuser) => {
                appendSubuser(subuser);
                dismiss();
            })
            .catch((error) => {
                console.error(error);
                setPropOverrides(null);
                clearAndAddHttpError({ key: 'user:edit', error });

                if (ref.current) {
                    ref.current.scrollIntoView();
                }
            });
    };

    useEffect(
        () => () => {
            clearFlashes('user:edit');
        },
        []
    );

    return (
        <Formik
            onSubmit={submit}
            initialValues={
                {
                    email: subuser?.email || '',
                    permissions: subuser?.permissions || [],
                    fileAccess: fileAccessToForm(subuser?.fileAccess),
                } as Values
            }
            validationSchema={object().shape({
                email: string()
                    .max(191, 'Email addresses must not exceed 191 characters.')
                    .email('A valid email address must be provided.')
                    .required('A valid email address must be provided.'),
                permissions: array().of(string()),
                fileAccess: object().shape(fileAccessValidation),
            })}
        >
            {({ values }) => (
                <Form>
                    <div css={tw`flex justify-between`}>
                        <h2 css={tw`text-2xl`} ref={ref}>
                            {subuser
                                ? `${canEditUser ? 'Modify' : 'View'} permissions for ${subuser.email}`
                                : 'Create new subuser'}
                        </h2>
                        <div>
                            <Button type={'submit'} css={tw`w-full sm:w-auto`}>
                                {subuser ? 'Save' : 'Invite User'}
                            </Button>
                        </div>
                    </div>
                    <FlashMessageRender byKey={'user:edit'} css={tw`mt-4`} />
                    {!isRootAdmin && loggedInPermissions[0] !== '*' && (
                        <div css={tw`mt-4 pl-4 py-2 border-l-4 border-cyan-400`}>
                            <p css={tw`text-sm text-neutral-300`}>
                                Only permissions which your account is currently assigned may be selected when
                                creating or modifying other users.
                            </p>
                        </div>
                    )}
                    {!subuser && (
                        <div css={tw`mt-6`}>
                            <Field
                                name={'email'}
                                label={'User Email'}
                                description={
                                    'Enter the email address of the user you wish to invite as a subuser for this server.'
                                }
                            />
                        </div>
                    )}
                    <div css={tw`my-6`}>
                        {Object.keys(permissions)
                            .filter((key) => key !== 'websocket')
                            .map((key, index) => (
                                <PermissionTitleBox
                                    key={`permission_${key}`}
                                    title={key}
                                    isEditable={canEditUser}
                                    permissions={Object.keys(permissions[key].keys).map((pkey) => `${key}.${pkey}`)}
                                    css={index > 0 ? tw`mt-4` : undefined}
                                >
                                    <p css={tw`text-sm text-neutral-400 mb-4`}>{permissions[key].description}</p>
                                    {Object.keys(permissions[key].keys).map((pkey) => (
                                        <PermissionRow
                                            key={`permission_${key}.${pkey}`}
                                            permission={`${key}.${pkey}`}
                                            disabled={!canEditUser || editablePermissions.indexOf(`${key}.${pkey}`) < 0}
                                        />
                                    ))}
                                </PermissionTitleBox>
                            ))}
                    </div>
                    <div css={tw`mt-10 mb-6`}>
                        <h3 css={tw`text-lg text-neutral-100`}>File Path Rules (Regex)</h3>
                        <p css={tw`text-xs text-neutral-400 mt-1`}>
                            Configure one regex per line. Use patterns without delimiters (example: {'`^/plugins/`'}).
                            Denylist matches always override allowlist matches.
                        </p>
                        {FILE_ACCESS_ACTIONS.map(({ action, permission, title, description }) => {
                            const selected = values.permissions.includes(permission);
                            const disabled = !canEditUser || !selected;

                            return (
                                <div key={`file_access_${action}`} css={tw`mt-4 p-4 rounded bg-neutral-800/40`}>
                                    <h4 css={tw`text-sm text-neutral-200`}>{title}</h4>
                                    <p css={tw`text-xs text-neutral-400 mt-1`}>{description}</p>
                                    {!selected && (
                                        <p css={tw`text-xs text-neutral-500 mt-2`}>
                                            Enable {permission} to configure these rules.
                                        </p>
                                    )}
                                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4 mt-3`}>
                                        <div>
                                            <Label htmlFor={`file_access_allow_${action}`}>Allowlist</Label>
                                            <FormikField
                                                as={Textarea}
                                                id={`file_access_allow_${action}`}
                                                name={`fileAccess.${action}.allow`}
                                                rows={4}
                                                disabled={disabled}
                                            />
                                            <p css={tw`text-xs text-neutral-500 mt-2`}>
                                                Leave blank to allow all paths not denied.
                                            </p>
                                        </div>
                                        <div>
                                            <Label htmlFor={`file_access_deny_${action}`}>Denylist</Label>
                                            <FormikField
                                                as={Textarea}
                                                id={`file_access_deny_${action}`}
                                                name={`fileAccess.${action}.deny`}
                                                rows={4}
                                                disabled={disabled}
                                            />
                                            <p css={tw`text-xs text-neutral-500 mt-2`}>
                                                Use trailing {'`/`'} in patterns to target directories.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <Can action={subuser ? 'user.update' : 'user.create'}>
                        <div css={tw`pb-6 flex justify-end`}>
                            <Button type={'submit'} css={tw`w-full sm:w-auto`}>
                                {subuser ? 'Save' : 'Invite User'}
                            </Button>
                        </div>
                    </Can>
                </Form>
            )}
        </Formik>
    );
};

export default asModal<Props>({
    top: false,
})(EditSubuserModal);
