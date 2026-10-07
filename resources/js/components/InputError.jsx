import React from 'react';

export default function InputError({ message, className = '' }) {
    if (!message) return null;

    return (
        <p className={`text-sm text-destructive ${className}`}>
            {message}
        </p>
    );
}
