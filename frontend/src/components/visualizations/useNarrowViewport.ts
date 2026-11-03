import { useEffect, useState } from 'react';

export function useNarrowViewport() {
  const [narrow, setNarrow] = useState(() => window.innerWidth < 640);
  useEffect(() => {
    const update = () => setNarrow(window.innerWidth < 640);
    window.addEventListener('resize', update);
    return () => window.removeEventListener('resize', update);
  }, []);
  return narrow;
}
