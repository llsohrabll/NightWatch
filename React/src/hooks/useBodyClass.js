import { useEffect } from 'react';

export default function useBodyClass(className) {
  useEffect(() => {
    document.body.classList.add(className);
    return () => document.body.classList.remove(className);
  }, [className]);
}
